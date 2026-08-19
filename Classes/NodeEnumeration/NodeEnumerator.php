<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\DocumentNodeFilter;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\NodeContextCombinator;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\NodeRenderingExtensionManager;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;

class NodeEnumerator
{
    #[Flow\Inject]
    protected DocumentNodeFilter $documentNodeFilter;

    /**
     * @Flow\Inject
     * @var RedisEnumerationRepository
     */
    protected $redisEnumerationRepository;

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
     * @var NodeRenderingExtensionManager
     */
    protected $nodeRenderingExtensionManager;

    public function enumerateAndStoreInRedis(
        ?Site $site,
        ContentReleaseLogger $contentReleaseLogger,
        ContentReleaseIdentifier $releaseIdentifier,
    ): void {
        $contentReleaseLogger->info('Starting content release', [
            'contentReleaseIdentifier' => $releaseIdentifier->jsonSerialize(),
        ]);

        // set content release status to running
        $currentMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($releaseIdentifier);
        $newMetadata = $currentMetadata->withStatus(NodeRenderingCompletionStatus::running());
        $this->redisContentReleaseService->setContentReleaseMetadata(
            $releaseIdentifier,
            $newMetadata,
            RedisInstanceIdentifier::primary(),
        );

        $this->redisEnumerationRepository->clearDocumentNodesEnumeration($releaseIdentifier);
        foreach (GeneratorUtility::createArrayBatch(
            $this->enumerateAll($site, $contentReleaseLogger, $newMetadata->getWorkspaceName()),
            100,
        ) as $enumeration) {
            $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($releaseIdentifier);
            // $enumeration is an array of EnumeratedNode, with at most 100 elements in it.

            $this->redisEnumerationRepository->addDocumentNodesToEnumeration($releaseIdentifier, ...$enumeration);

            $this->emitNodesEnumerated($enumeration, $releaseIdentifier, $contentReleaseLogger);
        }
    }

    /**
     * Emit {@see emitNodeEnumerated()} for a batch of nodes, including one another enumerator wrote.
     *
     * A signal is identified by the class which declares it, so a slot connected to this one hears nothing about the
     * nodes a quick release enumerates unless that release emits it from here as well - and the extra variants such
     * a slot adds for a document (pagination, filter arguments) would keep the rendering of the release which was
     * copied while the document itself is re-rendered.
     *
     * DEPRECATED: use extensions.documentRenderers.[...].enumeratorClassName instead
     *
     * @param array<int, EnumeratedNode> $enumeration
     */
    public function emitNodesEnumerated(
        array $enumeration,
        ContentReleaseIdentifier $releaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
    ): void {
        foreach ($enumeration as $enumeratedNode) {
            $this->emitNodeEnumerated($enumeratedNode, $releaseIdentifier, $contentReleaseLogger);
        }
    }

    /**
     * @return iterable<EnumeratedNode>
     * @throws Exception
     */
    private function enumerateAll(
        ?Site $site,
        ContentReleaseLogger $contentReleaseLogger,
        string $workspaceName,
    ): iterable {
        $combinator = new NodeContextCombinator();

        $nodeTypeFilter = $this->documentNodeFilter->flowQueryNodeTypeFilter();

        $queueSite = function (Site $site) use ($combinator, $nodeTypeFilter, $contentReleaseLogger, $workspaceName) {
            $contentReleaseLogger->debug('Publishing site', [
                'name' => $site->getName(),
                'domain' => $site->getFirstActiveDomain(),
            ]);

            foreach ($combinator->siteNodeInContexts($site, $workspaceName) as $siteNode) {
                $startTime = microtime(true);
                $dimensionValues = $siteNode->getContext()->getDimensions();

                $contentReleaseLogger->debug('Publishing dimension combination', [
                    'dimensionValues' => $dimensionValues,
                ]);

                $nodeQuery = new FlowQuery([$siteNode]);
                /** @var NodeInterface[] $matchingNodes */
                $matchingNodes = $nodeQuery->find($nodeTypeFilter)->add($siteNode)->get();

                foreach ($matchingNodes as $nodeToEnumerate) {
                    $contextPath = $nodeToEnumerate->getContextPath();

                    $skipReason = $this->documentNodeFilter->skipReason($nodeToEnumerate, $siteNode);
                    if ($skipReason !== null) {
                        $contentReleaseLogger->debug('Skipping node from publishing, because it is ' . $skipReason, [
                            'node' => $contextPath,
                        ]);
                        continue;
                    }

                    $contentReleaseLogger->debug('Registering node for publishing', [
                        'node' => $contextPath,
                    ]);

                    foreach ($this->nodeRenderingExtensionManager->enumerateDocumentNode(
                        $nodeToEnumerate,
                    ) as $enumeratedNode) {
                        yield $enumeratedNode;
                    }
                }
            }
            $contentReleaseLogger->debug(sprintf(
                'Finished enumerating site %s in %dms',
                $site->getName(),
                (microtime(true) - $startTime) * 1000,
            ));
        };

        if ($site === null) {
            foreach ($combinator->sites() as $siteInList) {
                yield from $queueSite($siteInList);
            }
        } else {
            yield from $queueSite($site);
        }
    }

    /**
     * A node was enumerated for a new content release.
     *
     * This signal can be used to add additional EnumeratedNode entries (e.g. with added arguments for pagination or filters) based on the given node.
     *
     * DEPRECATED: use extensions.documentRenderers.[...].enumeratorClassName instead
     *
     * @param EnumeratedNode $enumeratedNode
     * @param ContentReleaseIdentifier $releaseIdentifier
     * @param ContentReleaseLogger $contentReleaseLogger
     * @return void
     * @Flow\Signal
     */
    protected function emitNodeEnumerated(
        EnumeratedNode $enumeratedNode,
        ContentReleaseIdentifier $releaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
    ) {}
}
