<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheKey;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\NodeRenderingExtensionManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisContentCacheReader;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingStatisticsStore;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\ExitEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\RenderingIterationCompletedEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\RenderingQueueFilledEvent;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;

/**
 * TODO: explain concept of Working Set
 *
 * TODO: eventually consistent - kurz könnten Links kaputt sein
 * - Page A contains link to Page B
 * - Content release starts, enumeration lists page A and B
 * - Page A is rendered and added to content release
 * - Page B is deleted by an editor -> this flushes the cache of Page A (but does not touch the in-progress content release)
 *   - -> a new do_content_release job is added to the pipeline on the WAITING slot.
 * - Page B is attempted to be rendered (because part of enumeration, although it was already deleted (or hidden ...))
 * - Rendering for Page B FAILS (as node does not exist)
 * - -> Content Release aborts with error (and does not go live)
 *
 * - the new content release starts, enumeration lists page A (B has been deleted)
 * - Page A is rendered without the link to B.
 *
 *
 * MOVE of a page... auch kein Prbolem weil sich Node Context Path ändert.
 *
 * SCHWIERIGER: URL Segment wird geändert von Node.
 * -> Eventually consistent, kurzzeitig broken link.
 * ALTERNATIVE: Logik hier im Orchestrator ändern
 *
 * @Flow\Scope("singleton")
 */
class NodeRenderOrchestrator
{

    /**
     * @Flow\Inject
     * @var RedisEnumerationRepository
     */
    protected $redisEnumerationRepository;

    /**
     * @Flow\Inject
     * @var RedisRenderingQueue
     */
    protected $redisRenderingQueue;

    /**
     * @Flow\Inject
     * @var RedisContentCacheReader
     */
    protected $redisContentCacheReader;

    /**
     * @Flow\Inject
     * @var RedisRenderingErrorManager
     */
    protected $redisRenderingErrorManager;

    /**
     * @Flow\Inject
     * @var RedisRenderingStatisticsStore
     */
    protected $redisRenderingStatisticsStore;

    /**
     * @Flow\Inject
     * @var NodeRenderingExtensionManager
     */
    protected $nodeRenderingExtensionManager;

    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    /**
     * @Flow\Inject
     * @var ConcurrentBuildLockService
     */
    protected $concurrentBuildLockService;

    private const EXIT_ERRORSTATUSCODE_RELEASE_ALREADY_COMPLETED = 1;
    private const EXIT_ERRORSTATUSCODE_EMPTY_ENUMERATION = 2;
    private const EXIT_ERRORSTATUSCODE_RETRY_LIMIT_REACHED = 3;
    private const EXIT_ERRORSTATUSCODE_RENDERING_ERRORS = 4;

    /**
     * !!! You need to wrap this in the {@see InterruptibleProcessRuntime} so that it works correctly.
     *
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param ContentReleaseLogger $contentReleaseLogger
     */
    public function renderContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger): \Generator
    {
        $releaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        $renderStatus = $releaseMetadata->getStatus();

        if ($renderStatus->hasCompleted()) {
            $contentReleaseLogger->error('Release has already completed with status ' . $renderStatus->getDisplayName() . ', so we cannot render again.');
            yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RELEASE_ALREADY_COMPLETED);
            return;
        }

        $startTime = time();

        // Ensure we start with an empty queue here, in case this command is called multiple times.
        $this->redisRenderingQueue->flush($contentReleaseIdentifier);
        $this->redisRenderingErrorManager->flush($contentReleaseIdentifier);
        $this->redisRenderingStatisticsStore->flush($contentReleaseIdentifier);

        if ($this->redisEnumerationRepository->count($contentReleaseIdentifier) === 0) {
            $contentReleaseLogger->error('Content Enumeration is empty. This is dangerous; we never want this to go live. Exiting.');
            $this->redisContentReleaseService->setContentReleaseMetadata($contentReleaseIdentifier, $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()), RedisInstanceIdentifier::primary());
            yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_EMPTY_ENUMERATION);
            return;
        }

        $currentEnumeration = $this->redisEnumerationRepository->findAll($contentReleaseIdentifier);

        $i = 0;
        do {
            $i++;
            if ($i > 10) {
                $contentReleaseLogger->error('FAILED to build a complete content release after 10 rendering attempts. Exiting.');
                $this->redisContentReleaseService->setContentReleaseMetadata($contentReleaseIdentifier, $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()), RedisInstanceIdentifier::primary());
                yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RETRY_LIMIT_REACHED);
                return;
            }

            $contentReleaseLogger->info('Starting iteration ' . $i);
            $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($contentReleaseIdentifier);

            $this->redisRenderingStatisticsStore->addStatisticsIteration($contentReleaseIdentifier, RenderingStatistics::create(0, 0, []));

            // goTroughEnumeratedNodesFillContentReleaseAndCheckWhatStillNeedsToBeDone
            $nodesScheduledForRendering = [];
            foreach ($currentEnumeration as $enumeratedNode) {
                assert($enumeratedNode instanceof EnumeratedNode);

                $renderedDocumentFromContentCache = $this->redisContentCacheReader->tryToExtractRenderingForEnumeratedNodeFromContentCache(DocumentNodeCacheKey::fromEnumeratedNode($enumeratedNode));
                if ($renderedDocumentFromContentCache->isComplete()) {
                    $contentReleaseLogger->debug('Node fully rendered, adding to content release', ['node' => $enumeratedNode]);
                    // NOTE: Eventually consistent (TODO describe)
                    // If wanted more fully consistent, move to bottom....
                    $this->nodeRenderingExtensionManager->addRenderedDocumentToContentRelease($contentReleaseIdentifier, $renderedDocumentFromContentCache, $contentReleaseLogger);
                } else {
                    $contentReleaseLogger->debug('Scheduling rendering for Node, as it was not found or its content is incomplete: ' . $renderedDocumentFromContentCache->getIncompleteReason(), ['node' => $enumeratedNode]);
                    // the rendered document was not found, or has holes. so we need to re-render.
                    $nodesScheduledForRendering[] = $enumeratedNode;
                    $this->redisRenderingQueue->appendRenderingJob($contentReleaseIdentifier, $enumeratedNode);
                }
            }

            if (count($nodesScheduledForRendering) === 0) {
                // we have NO nodes scheduled for rendering anymore, so that means we FINISHED successfully.
                $contentReleaseLogger->info(sprintf('Everything rendered completely in %d seconds. Finishing RenderOrchestrator',  time() - $startTime));

                // info to all renderers that we finished, and they should terminate themselves gracefully.
                $this->redisContentReleaseService->setContentReleaseMetadata($contentReleaseIdentifier, $releaseMetadata->withStatus(NodeRenderingCompletionStatus::success())->withEndTime(new \DateTimeImmutable()), RedisInstanceIdentifier::primary());

                // Exit successfully.
                yield ExitEvent::createWithStatusCode(0);
                return;
            }

            // we remember the $totalJobsCount for displaying the rendering progress
            $totalJobsCount = count($nodesScheduledForRendering);
            // $remainingJobsCount is needed to figure out
            $remainingJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
            $renderingsPerSecondDataPoints = [];

            // at this point, we have:
            // - copied everything to the content release which was already fully rendered
            // - for everything else (stuff not rendered at all or not fully rendered), we enqueued them for rendering.
            //
            // Now, we need to wait for the rendering to complete.
            yield RenderingQueueFilledEvent::create();
            $contentReleaseLogger->info('Waiting for renderings to complete...');
            $waitTimer = 0;

            while ($this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier) > 0 || $this->redisRenderingQueue->numberOfRenderingsInProgress($contentReleaseIdentifier) > 0) {
                $this->redisRenderingStatisticsStore->replaceLastStatisticsIteration($contentReleaseIdentifier, RenderingStatistics::create($remainingJobsCount, $totalJobsCount, $renderingsPerSecondDataPoints));

                sleep(1);
                $waitTimer++;
                if ($waitTimer % 10 === 0) {
                    $previousRemainingJobs = $remainingJobsCount;
                    $remainingJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
                    $jobsWorkedThroughOverLastTenSeconds = $previousRemainingJobs - $remainingJobsCount;
                    $renderingsPerSecondDataPoints[] = $jobsWorkedThroughOverLastTenSeconds / 10;

                    $contentReleaseLogger->debug('Waiting... ', [
                        'numberOfQueuedJobs' => $remainingJobsCount,
                        'numberOfRenderingsInProgress' => $this->redisRenderingQueue->numberOfRenderingsInProgress($contentReleaseIdentifier),
                    ]);

                    $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($contentReleaseIdentifier);
                }
            }


            // NOTE: we do not abort rendering inside NodeRenderer when we encounter the first error, but we try to render
            // all pages in the full iteration until we stop the content release here.
            // This is to gain better visibility into all errors currently happening; and thus maybe being able to see
            // patterns among the errors.
            // We also do NOT start a new incremental release, as this would lead very likely to the same errors.
            $renderingErrors = $this->redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier);
            $amountOfRenderingErrors = count($renderingErrors);
            if ($amountOfRenderingErrors > 0) {
                $this->redisContentReleaseService->setContentReleaseMetadata($contentReleaseIdentifier, $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()), RedisInstanceIdentifier::primary());
                $contentReleaseLogger->error('In this iteration, there happened ' . $amountOfRenderingErrors . ' rendering errors. EXITING now, as there is no chance of completing the content release successfully.', [$renderingErrors]);
                yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RENDERING_ERRORS);
                return;
            }

            $remainingJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
            $this->redisRenderingStatisticsStore->replaceLastStatisticsIteration($contentReleaseIdentifier, RenderingStatistics::create($remainingJobsCount, $totalJobsCount, $renderingsPerSecondDataPoints));

            yield RenderingIterationCompletedEvent::create();

            $contentReleaseLogger->info('Rendering iteration completed. Continuing with next iteration.');
            // here, the rendering has completed. in the next iteration, we try to copy the
            // nodes which have been rendered in this iteration to the content store - so we iterate over the
            // just-rendered nodes.
            $currentEnumeration = $nodesScheduledForRendering;
        } while (!empty($currentEnumeration));
    }
}
