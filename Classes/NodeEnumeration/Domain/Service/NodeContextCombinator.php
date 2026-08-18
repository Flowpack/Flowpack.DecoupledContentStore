<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service;

use Flowpack\DecoupledContentStore\Exception\NodeNotFoundException;
use Generator;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
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
     * @return Generator<NodeInterface>
     * @throws NodeNotFoundException
     */
    public function nodeInContexts(string $nodeIdentifier, Site $site, string $workspaceName = 'live'): Generator
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
     * @return Generator<array{0: NodeInterface, 1: NodeInterface}> the site node and the node, in that order
     */
    public function nodeVariantsWithSiteNode(string $nodeIdentifier, string $workspaceName = 'live'): Generator
    {
        foreach ($this->sites() as $site) {
            // a flag rather than a plain return after the inner loop: a site which does not contain the node
            // yields nothing and has to fall through to the next site, and returning from inside the inner loop
            // would cut off the node's remaining dimension variants
            $nodeFound = false;

            // hidden nodes are shown here regardless of "recurseHiddenContent": somebody named this node by its
            // identifier, and "it is hidden" is the answer they need - a context which filters it away could only
            // report that it does not exist
            foreach ($this->siteNodeInContexts($site, $workspaceName, true) as $siteNode) {
                $node = $siteNode->getContext()->getNodeByIdentifier($nodeIdentifier);
                // getNodeByIdentifier() looks the node up in the whole content repository rather than inside the
                // site, so it answers for every site alike and the node has to be matched against the site itself
                if ($node instanceof NodeInterface && self::isWithinSiteNode($node, $siteNode)) {
                    $nodeFound = true;
                    yield [$siteNode, $node];
                }
            }

            if ($nodeFound) {
                // a node belongs to exactly one site, so the remaining ones cannot contribute further variants
                return;
            }
        }
    }

    /**
     * Whether the node is the site node itself or lives below it.
     *
     * Compared by path rather than by walking up the parents: the walk gives the same answer, while this runs for
     * every site the node is looked up in. Nodes without a path cannot be placed in a site and count as outside it.
     */
    private static function isWithinSiteNode(NodeInterface $node, NodeInterface $siteNode): bool
    {
        if (!$node instanceof TraversableNodeInterface || !$siteNode instanceof TraversableNodeInterface) {
            return false;
        }

        $nodePath = $node->findNodePath();
        $siteNodePath = $siteNode->findNodePath();

        return $nodePath->equals($siteNodePath) || str_starts_with((string)$nodePath, $siteNodePath . '/');
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
     * Iterate over the site node in all available presets (if it exists)
     *
     * @param bool|null $invisibleContentShown NULL follows the "nodeRendering.recurseHiddenContent" setting
     * @return Generator<NodeInterface>
     */
    public function siteNodeInContexts(
        Site $site,
        string $workspaceName = 'live',
        ?bool $invisibleContentShown = null
    ): Generator
    {
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
     * @return Generator<NodeInterface>
     */
    public function recurseDocumentChildNodes(NodeInterface $node): Generator
    {
        yield $node;

        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            yield from $this->recurseDocumentChildNodes($childNode);
        }
    }
}
