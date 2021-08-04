<?php
namespace Flowpack\DecoupledContentStore\NodeRendering\Render;

use Flowpack\DecoupledContentStore\Exception;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\ContentRepository\Domain\Model\NodeInterface;

class NodeContextCombinator
{

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\ContextFactoryInterface
     */
    protected $contextFactory;

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

        /** @var NodeInterface $siteNode */
        foreach ($this->siteNodeInContexts($site) as $siteNode) {
            $node = $siteNode->getContext()->getNodeByIdentifier($nodeIdentifier);

            if ($node instanceof NodeInterface) {
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
     * @return NodeInterface[]
     */
    public function siteNodeInContexts(Site $site)
    {
        $presets = $this->dimensionPresetSource->getAllPresets();
        if ($presets === []) {
            $contentContext = $this->contextFactory->create(array(
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

                    $contentContext = $this->contextFactory->create(array(
                        'currentSite' => $site,
                        'workspaceName' => 'live',
                        'dimensions' => $dimensions,
                        'targetDimensions' => []
                    ));

                    $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

                    if ($siteNode instanceof NodeInterface) {
                        yield $siteNode;
                    }
                }
            }
        }
    }

    /**
     * Iterate over the given node and all document child nodes recursively
     *
     * @param NodeInterface $node
     * @return NodeInterface[]
     */
    public function recurseDocumentChildNodes(NodeInterface $node)
    {
        yield $node;

        foreach ($node->getChildNodes('Neos.Neos:Document') as $node) {
            foreach ($this->recurseDocumentChildNodes($node) as $childNode) {
                yield $childNode;
            }
        }
    }

}