<?php
namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

use Flowpack\DecoupledContentStore\Exception;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\ContentRepository\Domain\Model\NodeInterface;

class NodeContextCombinator
{

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Iterate over the node with the given identifier and site in contexts for all available presets (if it exists as a variant)
     *
     * @param string $nodeIdentifier
     * @param Site $site
     * @return \Generator
     * @throws Exception\NodeNotFoundException
     */
    public function nodeInContexts($nodeIdentifier, Site $site)
    {
        $nodeFound = false;

        /** @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node $siteNode */
        foreach ($this->siteNodeInContexts($site) as $siteNode) {
            $node = $siteNode->getContext()->getNodeByIdentifier($nodeIdentifier);

            if ($node instanceof \Neos\ContentRepository\Core\Projection\ContentGraph\Node) {
                $nodeFound = true;
                yield $node;
            }
        }

        if (!$nodeFound) {
            throw new Exception\NodeNotFoundException('Could not find node by identifier ' . $nodeIdentifier . ' in any context',
                1467285561);
        }
    }

    /**
     * Iterate over all sites
     *
     * @return Site[]
     */
    public function sites()
    {
        $sites = $this->siteRepository->findAll();

        foreach ($sites as $site) {
            yield $site;
        }
    }

    /**
     * Iterate over the site node in all available presets (if it exists)
     *
     * @param Site $site
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node[]
     */
    public function siteNodeInContexts(Site $site)
    {
        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'));
        // TODO: FIX ME
        $presets = $contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority();
        if ($presets === []) {
            $contentContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(array(
                    'currentSite' => $site,
                    'workspaceName' => 'live',
                    'dimensions' => [],
                    'targetDimensions' => []
                ));

            $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

            yield $siteNode;
        } else {
            foreach ($presets as $dimensionIdentifier => $presetsConfiguration) {
                foreach ($presetsConfiguration['presets'] as $presetIdentifier => $presetConfiguration) {
                    $dimensions = [$dimensionIdentifier => $presetConfiguration['values']];

                    $contentContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(array(
                        'currentSite' => $site,
                        'workspaceName' => 'live',
                        'dimensions' => $dimensions,
                        'targetDimensions' => []
                    ));

                    $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

                    if ($siteNode instanceof \Neos\ContentRepository\Core\Projection\ContentGraph\Node) {
                        yield $siteNode;
                    }
                }
            }
        }
    }

    /**
     * Iterate over the given node and all document child nodes recursively
     *
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node[]
     */
    public function recurseDocumentChildNodes(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node)
    {
        yield $node;
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.


        foreach (iterator_to_array($subgraph->findChildNodes($node->nodeAggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::nodeTypeConstraints('Neos.Neos:Document'))) as $node) {
            foreach ($this->recurseDocumentChildNodes($node) as $childNode) {
                yield $childNode;
            }
        }
    }

}
