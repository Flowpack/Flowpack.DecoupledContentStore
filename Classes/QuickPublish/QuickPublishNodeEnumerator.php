<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Exception\InvalidReleaseException;
use Flowpack\DecoupledContentStore\Exception\NodeNotFoundException;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\DocumentNodeFilter;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\NodeContextCombinator;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\NodeRenderingExtensionManager;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderingUriService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Core\Cache\ContentCache;
use Neos\Neos\Fusion\Helper\CachingHelper;

/**
 * Writes the enumeration of a quick content release, which holds the given document nodes instead of every document
 * there is: everything else comes from the release this one was copied from.
 */
#[Flow\Scope('singleton')]
final class QuickPublishNodeEnumerator
{
    #[Flow\Inject]
    protected RedisEnumerationRepository $redisEnumerationRepository;

    #[Flow\Inject]
    protected RedisContentReleaseService $redisContentReleaseService;

    #[Flow\Inject]
    protected ConcurrentBuildLockService $concurrentBuildLockService;

    #[Flow\Inject]
    protected NodeRenderingExtensionManager $nodeRenderingExtensionManager;

    #[Flow\Inject]
    protected NodeContextCombinator $nodeContextCombinator;

    #[Flow\Inject]
    protected DocumentNodeFilter $documentNodeFilter;

    #[Flow\Inject]
    protected ContentCache $contentCache;

    #[Flow\Inject]
    protected CachingHelper $cachingHelper;

    #[Flow\Inject]
    protected NodeRenderingUriService $nodeRenderingUriService;

    #[Flow\Inject]
    protected ContentReleaseScope $contentReleaseScope;

    /**
     * @throws Exception if a given node cannot be published, or nothing is left to render
     */
    public function enumerateGivenNodesAndStoreInRedis(
        NodeIdentifiers $nodeIdentifiers,
        ContentReleaseLogger $contentReleaseLogger,
        ContentReleaseIdentifier $releaseIdentifier
    ): void {
        $contentReleaseLogger->info('Starting quick content release', [
            'contentReleaseIdentifier' => $releaseIdentifier->jsonSerialize(),
            'nodeIdentifiers' => $nodeIdentifiers->jsonSerialize()
        ]);

        $currentMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($releaseIdentifier);
        if ($currentMetadata === null) {
            throw new InvalidReleaseException(
                sprintf(
                    'Content release %s does not exist, so its nodes cannot be enumerated.',
                    $releaseIdentifier->getIdentifier()
                ),
                1786958512
            );
        }

        $newMetadata = $currentMetadata->withStatus(NodeRenderingCompletionStatus::running());
        $this->redisContentReleaseService->setContentReleaseMetadata(
            $releaseIdentifier,
            $newMetadata,
            RedisInstanceIdentifier::primary()
        );

        $this->redisEnumerationRepository->clearDocumentNodesEnumeration($releaseIdentifier);

        $nodesToRender = $this->enumerateGivenNodes(
            $nodeIdentifiers,
            $contentReleaseLogger,
            $newMetadata->getWorkspaceName() ?? 'live'
        );

        // a quick release which renders nothing publishes exactly the release it was copied from - which looks like
        // a successful publish to everybody watching, while the change the editor asked for is nowhere
        if ($nodesToRender === []) {
            throw new Exception(
                sprintf(
                    'None of the given nodes can be published (%s), so content release %s would only repeat the release '
                    . 'it was built on.',
                    (string) $nodeIdentifiers,
                    $releaseIdentifier->getIdentifier()
                ),
                1786958513
            );
        }

        foreach (GeneratorUtility::createArrayBatch(
            array_map(static fn(array $nodeToRender): EnumeratedNode => $nodeToRender[1], $nodesToRender),
            100
        ) as $enumeration) {
            $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($releaseIdentifier);
            $this->redisEnumerationRepository->addDocumentNodesToEnumeration($releaseIdentifier, ...$enumeration);
        }

        $this->writeChangedUrls($nodesToRender, $releaseIdentifier, $contentReleaseLogger);

        $contentReleaseLogger->info(sprintf('Enumerated %d node variants for rendering', count($nodesToRender)));
    }

    /**
     * The URLs of everything this release renders, for the validators which check a quick release instead of the
     * whole content store.
     *
     * They are built here rather than while enumerating, because {@see NodeRenderingUriService::buildNodeUri()}
     * marks the security context as initialized as a side effect, which would change what the node lookups of the
     * remaining identifiers are allowed to see.
     *
     * @param array<int, array{0: NodeInterface, 1: EnumeratedNode}> $nodesToRender
     */
    private function writeChangedUrls(
        array $nodesToRender,
        ContentReleaseIdentifier $releaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger
    ): void {
        $changedUrls = [];
        foreach ($nodesToRender as [$node, $enumeratedNode]) {
            $changedUrls[] = $this->nodeRenderingUriService->buildNodeUri($node, $enumeratedNode->getArguments());
        }

        $this->contentReleaseScope->setChangedUrls($releaseIdentifier, $changedUrls);

        $contentReleaseLogger->info('Content release is scoped to the URLs it renders', [
            'changedUrls' => $changedUrls
        ]);
    }

    /**
     * @return array<int, array{0: NodeInterface, 1: EnumeratedNode}>
     * @throws Exception
     */
    private function enumerateGivenNodes(
        NodeIdentifiers $nodeIdentifiers,
        ContentReleaseLogger $contentReleaseLogger,
        string $workspaceName
    ): array {
        $nodesToRender = [];

        foreach ($nodeIdentifiers as $nodeIdentifier) {
            $contentCacheFlushed = false;

            $variants = $this->nodeContextCombinator->nodeVariantsWithSiteNode($nodeIdentifier, $workspaceName);
            foreach ($variants as [$siteNode, $nodeToEnumerate]) {
                if (!$contentCacheFlushed) {
                    // the tags carry the node identifier and the workspace, so one flush covers every dimension
                    $this->flushContentCacheForNode($nodeToEnumerate, $nodeIdentifier, $contentReleaseLogger);
                    $contentCacheFlushed = true;
                }

                $contextPath = $nodeToEnumerate->getContextPath();

                $skipReason = $this->documentNodeFilter->skipReasonForNamedNode($nodeToEnumerate, $siteNode);
                if ($skipReason !== null) {
                    // warn rather than debug: somebody asked for this node by hand and will not see it change
                    $contentReleaseLogger->warn('Skipping node from publishing, because it is ' . $skipReason, [
                        'node' => $contextPath
                    ]);
                    continue;
                }

                $contentReleaseLogger->info('Registering node for publishing', ['node' => $contextPath]);

                foreach ($this->nodeRenderingExtensionManager->enumerateDocumentNode(
                    $nodeToEnumerate
                ) as $enumeratedNode) {
                    $nodesToRender[] = [$nodeToEnumerate, $enumeratedNode];
                }
            }

            if (!$contentCacheFlushed) {
                throw new NodeNotFoundException(
                    sprintf(
                        'Could not find node %s in any site and dimension, so it cannot be published.',
                        $nodeIdentifier
                    ),
                    1786958514
                );
            }
        }

        return $nodesToRender;
    }

    /**
     * A quick release renders a handful of documents into a copy of a finished release. If their cache entries are
     * still valid, that rendering is served straight from the content cache and the release publishes exactly what
     * it copied - the editor's change would be missing without a single error anywhere.
     */
    private function flushContentCacheForNode(
        NodeInterface $node,
        string $nodeIdentifier,
        ContentReleaseLogger $contentReleaseLogger
    ): void {
        $flushedEntriesCount = 0;
        foreach ($this->cachingHelper->nodeTag($node) as $tag) {
            $flushedEntriesCount += $this->contentCache->flushByTag($tag);
        }

        $contentReleaseLogger->info(sprintf(
            'Flushed %d content cache entries for node %s before re-rendering it',
            $flushedEntriesCount,
            $nodeIdentifier
        ));
    }
}
