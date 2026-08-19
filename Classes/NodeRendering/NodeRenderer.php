<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Exception\RenderingException;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\NodeRenderingExtensionManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\DocumentRenderedEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\ExitEvent;
use Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents\QueueEmptyEvent;
use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Fusion\Core\Cache\ContentCache;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Fusion\Helper\CachingHelper;

/**
 * Not called directly, but through Scripts/renderWorker.sh.
 * -> Return Code 193: Please Restart Me
 * -> Return Code 0: Success (all OK)
 * -> all other return codes: error
 *
 *
 * The NodeRenderer does NOT directly add the rendered document to the Content Release, in order to reduce special
 * cases and complexity. Instead, the NodeRenderer ONLY fills the Content Cache.
 *
 * This leads to unnecessary re-renderings in the following cases:
 * - A page has been rendered by NodeRenderer. Thus, it was added to the content cache.
 * - An editor does a change which flushes some cache tags.
 * - depending on the cache tags, this can lead to our just-rendered page to be deleted from the content cache again.
 * - After the rendering is complete, the NodeRenderOrchestrator again tries to copy the page to the content release from the cache,
 *   and this FAILS because it has been removed in the step before.
 * - Thus, a re-rendering is triggered.
 *
 * If the above happens often, we can add additional code to take care of this. Right now I do not want to
 * implement it to keep complexity low and keep the code paths in case of re-rendering or not re-rendering the same.
 *
 * @Flow\Scope("singleton")
 */
class NodeRenderer
{
    protected const RESTART_AFTER_RENDER_COUNT = 20;
    protected const CHECK_FOR_CONCURRENT_RELEASES_RENDER_COUNT = 5;

    /**
     * @Flow\Inject
     * @var DocumentRenderer
     */
    protected $documentRenderer;

    /**
     * @Flow\Inject
     * @var ContentCache
     */
    protected $contentCache;

    /**
     * @Flow\Inject
     * @var CachingHelper
     */
    protected $cachingHelper;

    /**
     * @Flow\InjectConfiguration("nodeRendering.flushDocumentCacheOnRetry")
     * @var bool
     */
    protected $flushDocumentCacheOnRetry;

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
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ConcurrentBuildLockService
     */
    protected $concurrentBuildLockService;

    /**
     * @Flow\Inject
     * @var NodeRenderingExtensionManager
     */
    protected $nodeRenderingExtensionManager;

    public function render(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
        RendererIdentifier $rendererIdentifier,
    ) {
        $contentReleaseLogger = $contentReleaseLogger->withRenderer($rendererIdentifier);

        $i = 0;
        while (true) {
            $renderStatus = $this->redisContentReleaseService
                ->fetchMetadataForContentRelease($contentReleaseIdentifier)
                ->getStatus();
            if ($renderStatus->hasCompleted()) {
                $contentReleaseLogger->info('Content release completed; so we terminate ourselves gracefully.');
                yield ExitEvent::createWithStatusCode(0);
                return;
            }

            $enumeratedNode = $this->redisRenderingQueue->fetchAndReserveNextRenderingJob(
                $contentReleaseIdentifier,
                $rendererIdentifier,
            );
            if ($enumeratedNode === null) {
                yield QueueEmptyEvent::create();
                // the queue is currently empty, but this does not necessarily mean that rendering is finished. Maybe the NodeRenderOrchestrator is still
                // determining what needs to be done. We just need to wait a bit and retry.
                $contentReleaseLogger->debug(
                    'Rendering queue currently empty; we wait a bit see if there is work for us.',
                );
                sleep(2);
                $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($contentReleaseIdentifier);
                continue;
            }

            $renderingAttempt = $this->redisRenderingQueue->registerRenderingAttempt(
                $contentReleaseIdentifier,
                $enumeratedNode,
            );

            try {
                $this->renderDocumentNodeVariant(
                    $enumeratedNode,
                    $contentReleaseIdentifier,
                    $contentReleaseLogger,
                    $renderingAttempt,
                );
                // BUGFIX: Because rendering a document node can update Thumbnails and their connected Persistent Resource
                // objects (= Doctrine Entities), we need to *persist* these changes. Otherwise, we will have broken
                // images, which will re-appear once you open the page in the backend (because image URL generation
                // is fully deterministic). This happened 12/2022 to us.
                $this->persistenceManager->persistAll();
            } finally {
                $removalSuccess = $this->redisRenderingQueue->removeRenderingJobFromReservedList(
                    $contentReleaseIdentifier,
                    $enumeratedNode,
                    $rendererIdentifier,
                );
                if ($removalSuccess === false) {
                    $contentReleaseLogger->warn(
                        'Node could not be removed from reserved-list, because it was claimed by some other worker in the meantime. We don not know yet how this case might happen.',
                        [
                            'node' => $enumeratedNode->debugString(),
                        ],
                    );
                }
            }
            yield DocumentRenderedEvent::create();

            $i++;

            if (
                static::CHECK_FOR_CONCURRENT_RELEASES_RENDER_COUNT > 0
                && ($i % static::CHECK_FOR_CONCURRENT_RELEASES_RENDER_COUNT) === 0
            ) {
                $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($contentReleaseIdentifier);
            }

            if (($i % static::RESTART_AFTER_RENDER_COUNT) === 0) {
                $contentReleaseLogger->info(sprintf(
                    'Restarting after %d renders.',
                    static::RESTART_AFTER_RENDER_COUNT,
                ));
                yield ExitEvent::createWithStatusCode(193);
                return;
            }
        }
    }

    // if node context path does not exist anymore, this is because between the enumeration and the rendering,
    // the node has been:
    // - deleted
    // - set to hidden
    // - MOVED to different location in the tree

    // at this point, there is no way that the existing content release can ever be completed successfully.
    // Thus, we can ABORT the full content release (by terminating this job).
    // TODO Before the abortion, we can schedule a new content release (just to be sure that we retry.)

    /**
     * Render a document node variant by context path
     *
     * @param string $nodeIdentifier The node identifier (needed if node was removed and context path cannot be resolved)
     * @param string $contextPath
     * @param array $arguments Request arguments when rendering the node
     * @param integer $releaseIdentifier
     * @param int $renderingAttempt Number of the current attempt for this node within this content release (1 = first attempt)
     */
    protected function renderDocumentNodeVariant(
        EnumeratedNode $enumeratedNode,
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
        int $renderingAttempt = 1,
    ) {
        $nodeWasFound = false;
        try {
            $node = $this->fetchRenderableNode($enumeratedNode);

            if (!$node instanceof NodeInterface) {
                // This could happen because a deleted node was queued after publishing
                $nodeWasFound = false;
            } else {
                $nodeWasFound = true;

                if ($renderingAttempt > 1) {
                    $this->flushContentCacheForNode($node, $enumeratedNode, $renderingAttempt, $contentReleaseLogger);
                }

                $contentReleaseLogger->debug('Rendering document node variant', [
                    'node' => $node->getContextPath(),
                    'nodeIdentifier' => $node->getIdentifier(),
                    'workspaceName' => $enumeratedNode->getWorkspaceNameFromContextPath(),
                    'dimensions' => $enumeratedNode->getDimensionsFromContextPath(),
                    'arguments' => $enumeratedNode->getArguments(),
                ]);

                $this->nodeRenderingExtensionManager->renderDocumentNodeVariant(
                    $node,
                    $enumeratedNode,
                    $contentReleaseLogger,
                );
            }

            // NOTE: we do not abort rendering directly, when we encounter any error, but we try to render
            // all pages in the full iteration (and then, if errors exist, we stop).
            // This is to gain better visibility into all errors currently happening; and thus maybe being able to see
            // patterns among the errors.
        } catch (\Neos\Flow\Property\Exception $exception) {
            $contentReleaseLogger->logException(
                $exception->getPrevious(),
                'Exception getting document node variant for rendering',
                array(
                    'node' => $enumeratedNode->debugString(),
                ),
            );

            $this->redisRenderingErrorManager->registerRenderingError(
                $contentReleaseIdentifier,
                ['node' => $enumeratedNode->debugString()],
                $exception->getPrevious(),
            );
        } catch (RenderingException $exception) {
            $contentReleaseLogger->logException(
                $exception->getPrevious(),
                'Exception while rendering document node variant',
                array(
                    'node' => $enumeratedNode->debugString(),
                    'nodeUri' => $exception->getNodeUri(),
                ),
            );

            $this->redisRenderingErrorManager->registerRenderingError(
                $contentReleaseIdentifier,
                ['node' => $enumeratedNode->debugString(), 'nodeUri' => $exception->getNodeUri()],
                $exception->getPrevious(),
            );
        } catch (\Exception $exception) {
            $contentReleaseLogger->logException($exception, 'Exception while rendering document node variant', array(
                'node' => $enumeratedNode->debugString(),
            ));

            $this->redisRenderingErrorManager->registerRenderingError(
                $contentReleaseIdentifier,
                ['node' => $enumeratedNode->debugString()],
                $exception,
            );
        }

        if (!$nodeWasFound) {
            // A node which was part of the ContentEnumeration was not found.
            // It is not possible to complete the content release successfully after this point in time, so
            // we register a rendering error and ensure a new incremental content release is started.
            //
            // NOTE: we do not directly abort (via exit(1)) the pipeline, as we want to get a list of all missing pages (and not just the first one).
            // Thus, we simply register a rendering error and ensure the next release will start after this one.s
            $this->redisRenderingErrorManager->registerRenderingError(
                $contentReleaseIdentifier,
                ['node' => $enumeratedNode->debugString()],
                new \Exception(
                    'We could not load a node which was part of the enumeration. At this point, the content release will definitely fail with no further possibility of recovery. Thus, we are exiting the rendering with an error',
                ),
            );
            $this->contentReleaseManager->startIncrementalContentRelease();
        }
    }

    /**
     * A node is handed out for rendering more than once if the previous rendering did not produce a complete content
     * cache entry for it - see {@see NodeRenderOrchestrator}.
     *
     * Simply rendering it again does not necessarily help: if the document's cache entries are still valid, the
     * rendering is served straight from the content cache. In that case Fusion never processes a document-level cache
     * segment, so {@see CacheUrlMappingAspect} does not write the "doc--..." mapping entry which the orchestrator is
     * waiting for - and the node is scheduled again, and again, until the retry limit aborts the whole release.
     *
     * Flushing the node's cache entries before the retry turns the re-rendering into a real rendering again.
     */
    private function flushContentCacheForNode(
        NodeInterface $node,
        EnumeratedNode $enumeratedNode,
        int $renderingAttempt,
        ContentReleaseLogger $contentReleaseLogger,
    ): void {
        if (!$this->flushDocumentCacheOnRetry) {
            return;
        }

        $flushedEntriesCount = 0;
        foreach ($this->cachingHelper->nodeTag($node) as $tag) {
            $flushedEntriesCount += $this->contentCache->flushByTag($tag);
        }

        $contentReleaseLogger->warn(
            sprintf(
                'Rendering attempt %d for this node; flushed %d content cache entries before re-rendering it.',
                $renderingAttempt,
                $flushedEntriesCount,
            ),
            [
                'node' => $enumeratedNode->debugString(),
            ],
        );
    }

    /**
     * @param EnumeratedNode $enumeratedNode
     * @return NodeInterface|null
     * @throws \Exception
     */
    protected function fetchRenderableNode(EnumeratedNode $enumeratedNode): ?NodeInterface
    {
        $site = $this->siteRepository->findOneByNodeName($enumeratedNode->getSiteNodeNameFromContextPath());

        $context = $this->contextFactory->create([
            'workspaceName' => $enumeratedNode->getWorkspaceNameFromContextPath(),
            'currentSite' => $site,
            'currentDomain' => $site->getFirstActiveDomain(),
            'dimensions' => $enumeratedNode->getDimensionsFromContextPath(),
        ]);
        return $context->getNodeByIdentifier($enumeratedNode->getNodeIdentifier());
    }
}
