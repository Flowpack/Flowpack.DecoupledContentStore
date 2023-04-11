<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service;

use Flowpack\DecoupledContentStore\Exception\NodeNotFoundException;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Domain\Repository\SiteRepository;

class NodeContextCombinator
{
    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Iterate over the node with the given identifier and site in contexts for all available presets (if it exists as a variant)
     *
     * @return \Generator<\Neos\ContentRepository\Core\Projection\ContentGraph\Node>
     * @throws NodeNotFoundException
     */
    public function nodeInContexts(string $nodeIdentifier, Site $site, string $workspaceName = 'live'): \Generator
    {
        $nodeFound = false;

        /** @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node $siteNode */
        foreach ($this->siteNodeInContexts($site, $workspaceName) as $siteNode) {
            $node = $siteNode->getContext()->getNodeByIdentifier($nodeIdentifier);

            if ($node instanceof \Neos\ContentRepository\Core\Projection\ContentGraph\Node) {
                $nodeFound = true;
                yield $node;
            }
        }

        if (!$nodeFound) {
            throw new NodeNotFoundException('Could not find node by identifier ' . $nodeIdentifier . ' in any context',
                1467285561);
        }
    }

    /**
     * Iterate over all sites
     *
     * @return \Generator<Site>
     */
    public function sites(): \Generator
    {
        $sites = $this->siteRepository->findAll();

        foreach ($sites as $site) {
            yield $site;
        }
    }

    /**
     * Iterate over the site node in all available presets (if it exists)
     *
     * @return \Generator<\Neos\ContentRepository\Core\Projection\ContentGraph\Node>
     */
    public function siteNodeInContexts(Site $site, string $workspaceName = 'live'): \Generator
    {
        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'));
        // TODO: FIX ME
        $presets = $contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority();
        if ($presets === []) {
            $contentContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(array(
                    'currentSite' => $site,
                    'workspaceName' => $workspaceName,
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
                        'workspaceName' => $workspaceName,
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
     * @return \Generator<\Neos\ContentRepository\Core\Projection\ContentGraph\Node>
     */
    public function recurseDocumentChildNodes(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): \Generator
    {
        yield $node;
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.


        foreach (iterator_to_array($subgraph->findChildNodes($node->nodeAggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::nodeTypeConstraints('Neos.Neos:Document'))) as $childNode) {
            yield from $this->recurseDocumentChildNodes($childNode);
        }
    }

}
