<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service;

use Generator;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

class NodeContextCombinator
{
    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\InjectConfiguration('nodeRendering.recurseHiddenContent')]
    protected bool $recurseHiddenContent;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Iterate over the node with the given identifier in every dimension it exists in.
     *
     * An identifier which is no valid node aggregate id yields nothing.
     *
     * @return Generator<Node>
     * @throws AccessDenied
     */
    public function nodeVariants(string $nodeIdentifier, string $workspaceName = 'live'): Generator
    {
        $nodeAggregateId = NodeAggregateId::tryFromString($nodeIdentifier);
        if ($nodeAggregateId === null) {
            return;
        }

        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'));
        $contentGraph = $contentRepository->getContentGraph(WorkspaceName::fromString($workspaceName));

        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            // hidden nodes are shown here regardless of "recurseHiddenContent": somebody named this node by its
            // identifier, and "it is hidden" is the answer they need - a subgraph which filters it away could only
            // report that it does not exist
            $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

            $node = $subgraph->findNodeById($nodeAggregateId);
            if ($node !== null) {
                yield $node;
            }
        }
    }

    /**
     * Iterate over all sites
     *
     * @return Generator<Site>
     */
    public function sites(): Generator
    {
        $sites = $this->siteRepository->findAll();

        foreach ($sites as $site) {
            yield $site;
        }
    }

    /**
     * Iterate over the site node in all dimension space points (if it exists), hiding disabled nodes unless
     * "nodeRendering.recurseHiddenContent" is set
     *
     * @return Generator<Node>
     * @throws AccessDenied
     */
    public function siteNodeInContexts(Site $site, string $workspaceName = 'live'): Generator
    {
        $contentRepository = $this->contentRepositoryRegistry->get($site->getConfiguration()->contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph(WorkspaceName::fromString($workspaceName));
        $visibilityConstraints = $this->recurseHiddenContent
            ? NeosVisibilityConstraints::excludeRemoved()
            : NeosVisibilityConstraints::excludeRemoved()->merge(NeosVisibilityConstraints::excludeDisabled());

        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, $visibilityConstraints);

            $sitesNode = $subgraph->findRootNodeByType(NodeTypeNameFactory::forSites());
            if ($sitesNode === null) {
                continue;
            }

            $siteNode = $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $sitesNode->aggregateId);
            if ($siteNode !== null) {
                yield $siteNode;
            }
        }
    }
}
