<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisContentReleaseSizeService;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\NodeRenderingExtensionManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingTimeStatisticsStore;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\ExitEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\RenderingIterationCompletedEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\RenderingQueueFilledEvent;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Core\Cache\ContentCache;
use Neos\Neos\Fusion\Helper\CachingHelper;

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
     * @var RedisRenderingErrorManager
     */
    protected $redisRenderingErrorManager;

    /**
     * @Flow\Inject
     * @var RedisRenderingTimeStatisticsStore
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

    /**
     * @Flow\Inject
     * @var RedisContentReleaseSizeService
     */
    protected $redisContentReleaseSizeService;

    #[Flow\Inject]
    protected ContentCache $contentCache;

    #[Flow\Inject]
    protected CachingHelper $cachingHelper;

    #[Flow\InjectConfiguration('nodeRendering.flushDocumentCacheOnRetry')]
    protected bool $flushDocumentCacheOnRetry;

    private const EXIT_ERRORSTATUSCODE_RELEASE_ALREADY_COMPLETED = 1;
    private const EXIT_ERRORSTATUSCODE_EMPTY_ENUMERATION = 2;
    private const EXIT_ERRORSTATUSCODE_RETRY_LIMIT_REACHED = 3;
    private const EXIT_ERRORSTATUSCODE_RENDERING_ERRORS = 4;

    /**
     * How often the very same set of nodes may be scheduled in a row before we give up on them. The rendering gets
     * one real retry (which flushes the content cache for these nodes, {@see flushContentCacheForRetry()}) before
     * this kicks in.
     */
    private const MAX_ITERATIONS_WITHOUT_PROGRESS = 3;

    /**
     * !!! You need to wrap this in the {@see InterruptibleProcessRuntime} so that it works correctly.
     *
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param ContentReleaseLogger $contentReleaseLogger
     */
    public function renderContentRelease(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
    ): \Generator {
        $releaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        if ($releaseMetadata === null) {
            throw new \RuntimeException(sprintf(
                'No metadata found for content release %s.',
                $contentReleaseIdentifier->getIdentifier(),
            ));
        }
        $renderStatus = $releaseMetadata->getStatus();

        if ($renderStatus->hasCompleted()) {
            $contentReleaseLogger->error(
                'Release has already completed with status '
                . $renderStatus->getDisplayName()
                . ', so we cannot render again.',
            );
            yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RELEASE_ALREADY_COMPLETED);
            return;
        }

        $startTime = time();

        // Ensure we start with an empty queue here, in case this command is called multiple times.
        $this->redisRenderingQueue->flush($contentReleaseIdentifier);
        $this->redisRenderingErrorManager->flush($contentReleaseIdentifier);
        $this->redisRenderingStatisticsStore->flush($contentReleaseIdentifier);

        if ($this->redisEnumerationRepository->count($contentReleaseIdentifier) === 0) {
            $contentReleaseLogger->error(
                'Content Enumeration is empty. This is dangerous; we never want this to go live. Exiting.',
            );
            $this->redisContentReleaseService->setContentReleaseMetadata(
                $contentReleaseIdentifier,
                $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()),
                RedisInstanceIdentifier::primary(),
            );
            yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_EMPTY_ENUMERATION);
            return;
        }

        $currentEnumeration = $this->redisEnumerationRepository->findAll($contentReleaseIdentifier);

        $previouslyScheduledNodes = null;
        $identicalIterationCount = 0;

        $i = 0;
        do {
            $i++;
            if ($i > 10) {
                $contentReleaseLogger->error(
                    'FAILED to build a complete content release after 10 rendering attempts. Exiting.',
                );
                $this->redisContentReleaseService->setContentReleaseMetadata(
                    $contentReleaseIdentifier,
                    $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()),
                    RedisInstanceIdentifier::primary(),
                );
                yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RETRY_LIMIT_REACHED);
                return;
            }

            $contentReleaseLogger->info('Starting iteration ' . $i);
            $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($contentReleaseIdentifier);

            $this->redisRenderingStatisticsStore->addStatisticsIteration($contentReleaseIdentifier, RenderingStatistics::create(
                0,
                0,
                [],
            ));

            // goTroughEnumeratedNodesFillContentReleaseAndCheckWhatStillNeedsToBeDone
            $nodesScheduledForRendering = [];
            // Retries are queued only after their cache entries are flushed, see flushContentCacheForRetry(); the first
            // iteration flushes nothing, so its jobs are queued right away to keep the render workers busy.
            $queueWhileChecking = $i === 1 || !$this->flushDocumentCacheOnRetry;
            foreach ($currentEnumeration as $enumeratedNode) {
                assert($enumeratedNode instanceof EnumeratedNode);

                $renderedDocumentFromContentCache =
                    $this->nodeRenderingExtensionManager->tryToExtractRenderingForEnumeratedNodeFromContentCache(
                        $enumeratedNode,
                    );
                if ($renderedDocumentFromContentCache->isComplete()) {
                    $contentReleaseLogger->debug('Node fully rendered, adding to content release', [
                        'url' => $renderedDocumentFromContentCache->getUrl(),
                        'node' => $enumeratedNode,
                    ]);
                    // NOTE: Eventually consistent (TODO describe)
                    // If wanted more fully consistent, move to bottom....
                    $this->nodeRenderingExtensionManager->addRenderedDocumentToContentRelease(
                        $contentReleaseIdentifier,
                        $enumeratedNode,
                        $renderedDocumentFromContentCache,
                        $contentReleaseLogger,
                    );
                } else {
                    $contentReleaseLogger->debug('Scheduling rendering for Node, as it was not found or its content is incomplete: '
                        . $renderedDocumentFromContentCache->getIncompleteReason(), [
                        'url' => $renderedDocumentFromContentCache->getUrl(),
                        'node' => $enumeratedNode,
                    ]);
                    // the rendered document was not found, or has holes. so we need to re-render.
                    $nodesScheduledForRendering[] = $enumeratedNode;
                    if ($queueWhileChecking) {
                        $this->redisRenderingQueue->appendRenderingJob($contentReleaseIdentifier, $enumeratedNode);
                    }
                }
            }

            if (empty($nodesScheduledForRendering)) {
                // we have NO nodes scheduled for rendering anymore, so that means we FINISHED successfully.
                yield from $this->completeContentRelease(
                    $contentReleaseIdentifier,
                    $contentReleaseLogger,
                    $releaseMetadata,
                    $startTime,
                );
                return;
            }

            // If an iteration schedules exactly the same nodes as the previous one, the renderings did not get us any
            // closer to a complete content release. Retrying this until the retry limit is reached only wastes time -
            // and (because no exception happened) leaves no trace anywhere. So we register a rendering error naming
            // these nodes, which makes them visible in the Backend UI.
            $scheduledNodes = array_map(fn(EnumeratedNode $enumeratedNode) => json_encode(
                $enumeratedNode,
            ), $nodesScheduledForRendering);
            sort($scheduledNodes);
            $identicalIterationCount = $scheduledNodes === $previouslyScheduledNodes ? $identicalIterationCount + 1 : 1;
            $previouslyScheduledNodes = $scheduledNodes;

            if ($identicalIterationCount >= self::MAX_ITERATIONS_WITHOUT_PROGRESS) {
                foreach ($nodesScheduledForRendering as $enumeratedNode) {
                    $this->redisRenderingErrorManager->registerRenderingError(
                        $contentReleaseIdentifier,
                        ['node' => $enumeratedNode->debugString()],
                        new \Exception(sprintf(
                            'This node was scheduled for rendering %d times in a row without ever becoming complete in the content cache. Check the render worker logs for this node - most likely no "doc--..." mapping entry is written for it.',
                            self::MAX_ITERATIONS_WITHOUT_PROGRESS,
                        )),
                    );
                }
                $this->redisContentReleaseService->setContentReleaseMetadata(
                    $contentReleaseIdentifier,
                    $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()),
                    RedisInstanceIdentifier::primary(),
                );
                $contentReleaseLogger->error(sprintf(
                    'The same %d nodes were scheduled for rendering %d iterations in a row without any progress. EXITING now.',
                    count($nodesScheduledForRendering),
                    self::MAX_ITERATIONS_WITHOUT_PROGRESS,
                ));
                yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RENDERING_ERRORS);
                return;
            }

            if (!$queueWhileChecking) {
                $this->flushContentCacheForRetry($nodesScheduledForRendering, $i, $contentReleaseLogger);
                foreach ($nodesScheduledForRendering as $enumeratedNode) {
                    $this->redisRenderingQueue->appendRenderingJob($contentReleaseIdentifier, $enumeratedNode);
                }
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
            $lastDataPointTime = microtime(true);
            $nodesAddedToContentRelease = [];

            while (
                $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier) > 0
                || $this->redisRenderingQueue->numberOfRenderingsInProgress($contentReleaseIdentifier) > 0
            ) {
                $passDeadline = microtime(true) + 1;
                $nodesAddedToContentRelease += $this->addReportedRenderingsToContentRelease(
                    $contentReleaseIdentifier,
                    $contentReleaseLogger,
                    $passDeadline,
                );
                $this->redisRenderingStatisticsStore->replaceLastStatisticsIteration($contentReleaseIdentifier, RenderingStatistics::create(
                    $remainingJobsCount,
                    $totalJobsCount,
                    $renderingsPerSecondDataPoints,
                ));

                // A backlog of reported renderings uses up the whole pass and is worked off without pausing.
                $secondsLeftInPass = $passDeadline - microtime(true);
                if ($secondsLeftInPass > 0) {
                    usleep((int) ($secondsLeftInPass * 1_000_000));
                }
                // Measured in wall-clock time: the deadline is only checked between two renderings, so a pass can take
                // longer than a second.
                $secondsSinceLastDataPoint = microtime(true) - $lastDataPointTime;
                if ($secondsSinceLastDataPoint >= 10) {
                    $lastDataPointTime = microtime(true);
                    $previousRemainingJobs = $remainingJobsCount;
                    $remainingJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
                    $jobsWorkedThroughSinceLastDataPoint = $previousRemainingJobs - $remainingJobsCount;
                    $renderingsPerSecondDataPoints[] =
                        $jobsWorkedThroughSinceLastDataPoint / $secondsSinceLastDataPoint;

                    $contentReleaseLogger->debug('Waiting... ', [
                        'numberOfQueuedJobs' => $remainingJobsCount,
                        'numberOfRenderingsInProgress' =>
                            $this->redisRenderingQueue->numberOfRenderingsInProgress($contentReleaseIdentifier),
                    ]);

                    $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($contentReleaseIdentifier);
                }
            }
            $nodesAddedToContentRelease += $this->addReportedRenderingsToContentRelease(
                $contentReleaseIdentifier,
                $contentReleaseLogger,
                null,
            );

            // NOTE: we do not abort rendering inside NodeRenderer when we encounter the first error, but we try to render
            // all pages in the full iteration until we stop the content release here.
            // This is to gain better visibility into all errors currently happening; and thus maybe being able to see
            // patterns among the errors.
            // We also do NOT start a new incremental release, as this would lead very likely to the same errors.
            $renderingErrors = $this->redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier);
            $amountOfRenderingErrors = count($renderingErrors);
            if ($amountOfRenderingErrors > 0) {
                $this->redisContentReleaseService->setContentReleaseMetadata(
                    $contentReleaseIdentifier,
                    $releaseMetadata->withStatus(NodeRenderingCompletionStatus::failed()),
                    RedisInstanceIdentifier::primary(),
                );
                $contentReleaseLogger->error('In this iteration, there happened '
                . $amountOfRenderingErrors
                . ' rendering errors. EXITING now, as there is no chance of completing the content release successfully.', [
                    $renderingErrors,
                ]);
                yield ExitEvent::createWithStatusCode(self::EXIT_ERRORSTATUSCODE_RENDERING_ERRORS);
                return;
            }

            $remainingJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
            $this->redisRenderingStatisticsStore->replaceLastStatisticsIteration($contentReleaseIdentifier, RenderingStatistics::create(
                $remainingJobsCount,
                $totalJobsCount,
                $renderingsPerSecondDataPoints,
            ));

            yield RenderingIterationCompletedEvent::create();

            // The next iteration checks the content cache once more for every node which did not make it into the
            // content release yet - those whose content cache entry already had holes when its rendering was
            // reported, and any whose report went missing.
            $currentEnumeration = array_values(array_filter(
                $nodesScheduledForRendering,
                fn(EnumeratedNode $enumeratedNode) => !array_key_exists(
                    json_encode($enumeratedNode, JSON_THROW_ON_ERROR),
                    $nodesAddedToContentRelease,
                ),
            ));
            if (empty($currentEnumeration)) {
                yield from $this->completeContentRelease(
                    $contentReleaseIdentifier,
                    $contentReleaseLogger,
                    $releaseMetadata,
                    $startTime,
                );
                return;
            }
            $contentReleaseLogger->info(sprintf(
                'Rendering iteration completed; %d of %d rendered nodes are not in the content release yet. Continuing with next iteration.',
                count($currentEnumeration),
                $totalJobsCount,
            ));
        } while (true);
    }

    /**
     * Simply rendering a node again does not necessarily help: if its content cache entries are still valid, the
     * rendering is served straight from the content cache. Fusion then never processes a document-level cache
     * segment, so {@see \Flowpack\DecoupledContentStore\Aspects\CacheUrlMappingAspect} does not write the "doc--..."
     * mapping entry the content release needs - and the node is scheduled again, and again, until the retry limit
     * aborts the whole release. Flushing the node's cache entries turns the re-rendering into a real rendering.
     *
     * This happens here and before the rendering jobs are queued: the node tag covers all dimension variants of a
     * node, so a flush by a render worker wipes the variants other workers have just rendered.
     *
     * @param EnumeratedNode[] $nodesScheduledForRendering
     */
    private function flushContentCacheForRetry(
        array $nodesScheduledForRendering,
        int $iteration,
        ContentReleaseLogger $contentReleaseLogger,
    ): void {
        $tags = [];
        foreach ($nodesScheduledForRendering as $enumeratedNode) {
            $tags[] =
                'Node_'
                . $this->cachingHelper->renderWorkspaceTagForContextNode(
                    $enumeratedNode->getWorkspaceNameFromContextPath(),
                )
                . '_'
                . $enumeratedNode->getNodeIdentifier();
        }

        foreach (array_unique($tags) as $tag) {
            $contentReleaseLogger->warn(sprintf(
                'Iteration %d: flushed %d content cache entries for tag %s before rendering its nodes again.',
                $iteration,
                $this->contentCache->flushByTag($tag),
                $tag,
            ));
        }
    }

    private function completeContentRelease(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
        ContentReleaseMetadata $releaseMetadata,
        int $startTime,
    ): \Generator {
        $contentReleaseLogger->info(sprintf(
            'Everything rendered completely in %d seconds. Finishing RenderOrchestrator',
            time() - $startTime,
        ));

        // The release is complete now, so this is the point where we can determine its size once. Calculating
        // it is expensive, which is why the Backend UI relies on this stored value instead of re-calculating it.
        $contentReleaseSize = $this->redisContentReleaseSizeService->calculateReleaseSize(
            RedisInstanceIdentifier::primary(),
            $contentReleaseIdentifier,
        );
        $contentReleaseLogger->info(sprintf('Content release size: %.2f MB', $contentReleaseSize));

        // info to all renderers that we finished, and they should terminate themselves gracefully.
        $this->redisContentReleaseService->setContentReleaseMetadata(
            $contentReleaseIdentifier,
            $releaseMetadata
                ->withStatus(NodeRenderingCompletionStatus::success())
                ->withEndTime(new \DateTimeImmutable())
                ->withContentReleaseSize($contentReleaseSize),
            RedisInstanceIdentifier::primary(),
        );

        // Exit successfully.
        yield ExitEvent::createWithStatusCode(0);
    }

    /**
     * Copies every node the render workers reported as rendered ({@see RedisRenderingQueue::reportRenderedJob()})
     * from the content cache to the content release.
     *
     * This happens while the rendering iteration is still running: an editor publishing in the content module flushes
     * content cache tags, and the longer a rendered page waits in the content cache, the likelier it is gone again -
     * on a busy day often enough to run the release into the iteration limit.
     *
     * A node whose content cache entry already has holes is left alone; the next iteration schedules it again.
     *
     * Workers can report faster than a single orchestrator adds, so while rendering is running a deadline hands
     * control back to the wait loop, which keeps the statistics and the concurrent release check going.
     *
     * @param float|null $deadline Unix timestamp (microtime) after which no further rendering is added; null for none
     * @return array<string, true> JSON-encoded nodes which were added to the content release
     */
    private function addReportedRenderingsToContentRelease(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
        ?float $deadline,
    ): array {
        $nodesAddedToContentRelease = [];
        while ($deadline === null || microtime(true) < $deadline) {
            $enumeratedNode = $this->redisRenderingQueue->fetchNextRenderedJob($contentReleaseIdentifier);
            if ($enumeratedNode === null) {
                break;
            }
            $renderedDocumentFromContentCache =
                $this->nodeRenderingExtensionManager->tryToExtractRenderingForEnumeratedNodeFromContentCache(
                    $enumeratedNode,
                );
            if (!$renderedDocumentFromContentCache->isComplete()) {
                $contentReleaseLogger->debug('Rendered node is incomplete in the content cache, it is scheduled again in the next iteration: '
                    . $renderedDocumentFromContentCache->getIncompleteReason(), [
                    'url' => $renderedDocumentFromContentCache->getUrl(),
                    'node' => $enumeratedNode,
                ]);
                continue;
            }

            $contentReleaseLogger->debug('Node rendered, adding to content release', [
                'url' => $renderedDocumentFromContentCache->getUrl(),
                'node' => $enumeratedNode,
            ]);
            $this->nodeRenderingExtensionManager->addRenderedDocumentToContentRelease(
                $contentReleaseIdentifier,
                $enumeratedNode,
                $renderedDocumentFromContentCache,
                $contentReleaseLogger,
            );
            $nodesAddedToContentRelease[json_encode($enumeratedNode, JSON_THROW_ON_ERROR)] = true;
        }
        return $nodesAddedToContentRelease;
    }
}
