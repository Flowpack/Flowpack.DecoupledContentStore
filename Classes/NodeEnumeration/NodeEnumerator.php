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
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraintFactory;
use Neos\ContentRepository\Domain\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;

class NodeEnumerator
{
    /**
     * @Flow\Inject
     * @var NodeTypeConstraintFactory
     */
    protected $nodeTypeConstraintFactory;

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
    protected $nodeTypeWhitelist;

    public function enumerateAndStoreInRedis(?Site $site, ContentReleaseLogger $contentReleaseLogger, ContentReleaseIdentifier $releaseIdentifier): void
    {
        $contentReleaseLogger->info('Starting content release', ['contentReleaseIdentifier' => $releaseIdentifier->jsonSerialize()]);

        // set content release status to running
        $currentMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($releaseIdentifier);
        $newMetadata = $currentMetadata->withStatus(NodeRenderingCompletionStatus::running());
        $this->redisContentReleaseService->setContentReleaseMetadata($releaseIdentifier, $newMetadata, RedisInstanceIdentifier::primary());

        $this->redisEnumerationRepository->clearDocumentNodesEnumeration($releaseIdentifier);
        foreach (GeneratorUtility::createArrayBatch($this->enumerateAll($site, $contentReleaseLogger, $newMetadata->getWorkspaceName()), 100) as $enumeration) {
            $this->concurrentBuildLockService->assertNoOtherContentReleaseWasStarted($releaseIdentifier);
            // $enumeration is an array of EnumeratedNode, with at most 100 elements in it.
            // TODO: EXTENSION POINT HERE, TO ADD ADDITIONAL ENUMERATIONS (.metadata.json f.e.)
            // TODO: not yet fully sure how to handle Enumeration
            $this->redisEnumerationRepository->addDocumentNodesToEnumeration($releaseIdentifier, ...$enumeration);
        }
    }

    /**
     * @return iterable<EnumeratedNode>
     */
    private function enumerateAll(?Site $site, ContentReleaseLogger $contentReleaseLogger, string $workspaceName): iterable
    {
        $combinator = new NodeContextCombinator();

        $nodeTypeWhitelist = $this->nodeTypeConstraintFactory->parseFilterString($this->nodeTypeWhitelist);

        $queueSite = function (Site $site) use ($combinator, &$documentNodeVariantsToRender, $nodeTypeWhitelist, $contentReleaseLogger, $workspaceName) {
            $contentReleaseLogger->debug('Publishing site', [
                'name' => $site->getName(),
                'domain' => $site->getFirstActiveDomain()
            ]);
            foreach ($combinator->siteNodeInContexts($site, $workspaceName) as $siteNode) {
                $dimensionValues = $siteNode->getContext()->getDimensions();

                $contentReleaseLogger->debug('Publishing dimension combination', [
                    'dimensionValues' => $dimensionValues
                ]);

                foreach ($combinator->recurseDocumentChildNodes($siteNode) as $documentNode) {
                    $contextPath = $documentNode->getContextPath();

                    if ($documentNode->isHidden()) {
                        $contentReleaseLogger->debug('Skipping node from publishing, because it is hidden', [
                            'node' => $contextPath,
                        ]);
                    } else if ($nodeTypeWhitelist->matches(NodeTypeName::fromString($documentNode->getNodeType()->getName()))) {
                        $contentReleaseLogger->debug('Registering node for publishing', [
                            'node' => $contextPath
                        ]);

                        yield EnumeratedNode::fromNode($documentNode);
                    } else {
                        $contentReleaseLogger->debug('Skipping node from publishing, because it did not match the configured nodeTypeWhitelist', [
                            'node' => $contextPath,
                            'nodeTypeWhitelist' => $this->nodeTypeWhitelist
                        ]);
                    }
                }
            }
        };

        if ($site === null) {
            foreach ($combinator->sites() as $site) {
                yield from $queueSite($site);
            }
        } else {
            yield from $queueSite($site);
        }
    }

}
