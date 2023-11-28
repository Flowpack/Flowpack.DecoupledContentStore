<?php

namespace Flowpack\DecoupledContentStore\NodeEnumeration;


use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\NodeContextCombinator;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;

class NodeEnumerator
{

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
     * @Flow\InjectConfiguration("nodeRendering.nodeTypeWhitelist")
     * @var string
     */
    protected $nodeTypeList;

    public function enumerateAndStoreInRedis(
        ?Site $site,
        ContentReleaseLogger $contentReleaseLogger,
        ContentReleaseIdentifier $releaseIdentifier
    ): void {
        $contentReleaseLogger->info(
            'Starting content release',
            ['contentReleaseIdentifier' => $releaseIdentifier->jsonSerialize()]
        );

        // set content release status to running
        $currentMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($releaseIdentifier);
        $newMetadata = $currentMetadata->withStatus(NodeRenderingCompletionStatus::running());
        $this->redisContentReleaseService->setContentReleaseMetadata(
            $releaseIdentifier,
            $newMetadata,
            RedisInstanceIdentifier::primary()
        );

        $this->redisEnumerationRepository->clearDocumentNodesEnumeration($releaseIdentifier);
        foreach (
            GeneratorUtility::createArrayBatch(
                $this->enumerateAll($site, $contentReleaseLogger, $newMetadata->getWorkspaceName()),
                100
            ) as $enumeration
        ) {
            $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($releaseIdentifier);
            // $enumeration is an array of EnumeratedNode, with at most 100 elements in it.
            // TODO: EXTENSION POINT HERE, TO ADD ADDITIONAL ENUMERATIONS (.metadata.json f.e.)
            // TODO: not yet fully sure how to handle Enumeration
            $this->redisEnumerationRepository->addDocumentNodesToEnumeration($releaseIdentifier, ...$enumeration);
        }
    }

    /**
     * @return iterable<EnumeratedNode>
     * @throws Exception
     */
    private function enumerateAll(
        ?Site $site,
        ContentReleaseLogger $contentReleaseLogger,
        string $workspaceName
    ): iterable {
        $combinator = new NodeContextCombinator();

        // Build filter from allowed/disallowed nodetypes
        $nodeTypeList = explode(',', $this->nodeTypeList ?: 'Neos.Neos:Document');
        $nodeTypeFilter = implode(
            ',',
            array_map(static function ($nodeType) {
                if ($nodeType[0] === '!') {
                    return '[!instanceof ' . substr($nodeType, 1) . ']';
                }
                return '[instanceof ' . $nodeType . ']';
            }, $nodeTypeList)
        );

        $queueSite = static function (Site $site) use (
            $combinator,
            $nodeTypeFilter,
            $contentReleaseLogger,
            $workspaceName
        ) {
            $contentReleaseLogger->debug('Publishing site', [
                'name' => $site->getName(),
                'domain' => $site->getFirstActiveDomain()
            ]);
            foreach ($combinator->siteNodeInContexts($site, $workspaceName) as $siteNode) {
                $startTime = microtime(true);
                $dimensionValues = $siteNode->getContext()->getDimensions();

                $contentReleaseLogger->debug('Publishing dimension combination', [
                    'dimensionValues' => $dimensionValues
                ]);

                $nodeQuery = new FlowQuery([$siteNode]);
                /** @var NodeInterface[] $matchingNodes */
                $matchingNodes = $nodeQuery->find($nodeTypeFilter)->add($siteNode)->get();

                foreach ($matchingNodes as $nodeToEnumerate) {
                    $contextPath = $nodeToEnumerate->getContextPath();

                    // Verify that the node is not orphaned
                    $parentNode = $nodeToEnumerate->getParent();
                    while ($parentNode !== $siteNode) {
                        if ($parentNode === null) {
                            $contentReleaseLogger->debug('Skipping node from publishing, because it is orphaned', [
                                'node' => $contextPath,
                            ]);
                            // Continue with the next document
                            continue 2;
                        }
                        $parentNode = $parentNode->getParent();
                    }

                    if ($nodeToEnumerate->isHidden()) {
                        $contentReleaseLogger->debug('Skipping node from publishing, because it is hidden', [
                            'node' => $contextPath,
                        ]);
                    } else {
                        $contentReleaseLogger->debug('Registering node for publishing', [
                            'node' => $contextPath
                        ]);
                        yield EnumeratedNode::fromNode($nodeToEnumerate);
                    }
                }
            }
            $contentReleaseLogger->debug(
                sprintf('Finished enumerating site %s in %dms', $site->getName(), (microtime(true) - $startTime) * 1000)
            );
        };

        if ($site === null) {
            foreach ($combinator->sites() as $siteInList) {
                yield from $queueSite($siteInList);
            }
        } else {
            yield from $queueSite($site);
        }
    }

}
