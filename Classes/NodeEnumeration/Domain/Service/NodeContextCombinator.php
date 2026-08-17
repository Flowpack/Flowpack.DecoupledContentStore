<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service;

use Flowpack\DecoupledContentStore\Exception\NodeNotFoundException;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Domain\Repository\SiteRepository;

class NodeContextCombinator
{
    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

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
            throw new NodeNotFoundException(
                'Could not find node by identifier ' . $nodeIdentifier . ' in any context',
                1467285561
            );
        }
    }

    /**
     * Iterate over the node with the given identifier in every dimension it exists in, together with the site node
     * it belongs to.
     *
     * Unlike {@see nodeInContexts()} this searches every site and hands out the site node as well, which callers
     * need for the orphan check. It also does not report "not found" per site: a node is part of one site, so all
     * the others not having it is the normal case rather than an error.
     *
     * @return \Generator<array{0: NodeInterface, 1: NodeInterface}> the site node and the node, in that order
     */
    public function nodeVariantsWithSiteNode(string $nodeIdentifier, string $workspaceName = 'live'): \Generator
    {
        foreach ($this->sites() as $site) {
            $nodeFound = false;

            // hidden nodes are shown here regardless of "recurseHiddenContent": somebody named this node by its
            // identifier, and "it is hidden" is the answer they need - a context which filters it away could only
            // report that it does not exist
            foreach ($this->siteNodeInContexts($site, $workspaceName, true) as $siteNode) {
                $node = $siteNode->getContext()->getNodeByIdentifier($nodeIdentifier);
                if ($node instanceof NodeInterface) {
                    $nodeFound = true;
                    yield [$siteNode, $node];
                }
            }

            if ($nodeFound) {
                // getNodeByIdentifier() looks the node up in the whole content repository rather than inside the
                // site, so every further site would hand out the very same variants again
                return;
            }
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
     * @param bool|null $invisibleContentShown NULL follows the "nodeRendering.recurseHiddenContent" setting
     * @return \Generator<NodeInterface>
     */
    public function siteNodeInContexts(
        Site $site,
        string $workspaceName = 'live',
        ?bool $invisibleContentShown = null
    ): \Generator {
        $allowedContextCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();

        foreach ($allowedContextCombinations as $dimensionContextCombination) {
            $contentContext = $this->contextFactory->create(array(
                'currentSite' => $site,
                'workspaceName' => $workspaceName,
                'dimensions' => $dimensionContextCombination,
                'targetDimensions' => [],
                'invisibleContentShown' => $invisibleContentShown ?? $this->recurseHiddenContent
            ));

            $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

            if ($siteNode instanceof NodeInterface) {
                yield $siteNode;
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
