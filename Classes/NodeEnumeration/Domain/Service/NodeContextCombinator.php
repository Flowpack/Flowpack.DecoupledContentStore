<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service;

use Flowpack\DecoupledContentStore\Exception\NodeNotFoundException;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

class NodeContextCombinator
{

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

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
     * @Flow\InjectConfiguration(path="nodeRendering.recurseHiddenContent", package="Flowpack.DecoupledContentStore")
     * @var ContextFactoryInterface
     */
    protected $recurseHiddenContent;

    /**
     * Iterate over the node with the given identifier and site in contexts for all available presets (if it exists as a variant)
     *
     * @return \Generator<NodeInterface>
     * @throws NodeNotFoundException
     */
    public function nodeInContexts(string $nodeIdentifier, Site $site, string $workspaceName = 'live'): \Generator
    {
        $nodeFound = false;

        /** @var NodeInterface $siteNode */
        foreach ($this->siteNodeInContexts($site, $workspaceName) as $siteNode) {
            $node = $siteNode->getContext()->getNodeByIdentifier($nodeIdentifier);

            if ($node instanceof NodeInterface) {
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
     * @return \Generator<NodeInterface>
     */
    public function siteNodeInContexts(Site $site, string $workspaceName = 'live'): \Generator
    {
        $presets = $this->dimensionPresetSource->getAllPresets();
        if ($presets === []) {
            $contentContext = $this->contextFactory->create(array(
                    'currentSite' => $site,
                    'workspaceName' => $workspaceName,
                    'dimensions' => [],
                    'targetDimensions' => [],
                    'invisibleContentShown' => $this->recurseHiddenContent,
                ));

            $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

            yield $siteNode;
        } else {
            foreach ($presets as $dimensionIdentifier => $presetsConfiguration) {
                foreach ($presetsConfiguration['presets'] as $presetIdentifier => $presetConfiguration) {
                    $dimensions = [$dimensionIdentifier => $presetConfiguration['values']];

                    $contentContext = $this->contextFactory->create(array(
                        'currentSite' => $site,
                        'workspaceName' => $workspaceName,
                        'dimensions' => $dimensions,
                        'targetDimensions' => [],
                        'invisibleContentShown' => $this->recurseHiddenContent,
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
     * @return \Generator<NodeInterface>
     */
    public function recurseDocumentChildNodes(NodeInterface $node): \Generator
    {
        yield $node;

        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            yield from $this->recurseDocumentChildNodes($childNode);
        }
    }

}
