<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;

/**
 * Decides which document nodes end up in a content release.
 *
 * The full enumeration walks the node tree with a FlowQuery filter, while a quick release starts from node
 * identifiers an editor gave it - so the same "nodeRendering.nodeTypeWhitelist" setting has to be expressed both as
 * a filter string and as a check for a single node.
 */
#[Flow\Scope('singleton')]
final class DocumentNodeFilter
{
    /**
     * Used when "nodeRendering.nodeTypeWhitelist" configures no node type to include.
     */
    private const DEFAULT_NODE_TYPE = 'Neos.Neos:Document';

    /**
     * @var array<int, string>
     */
    #[Flow\InjectConfiguration('nodeRendering.nodeTypeWhitelist')]
    protected array $nodeTypeWhitelist;

    /**
     * Builds a FlowQuery filter string from the node type whitelist,
     * where entries prefixed with "!" are excluded.
     *
     * The filter parts must be concatenated without a separator: FlowQuery parses
     * comma-separated filter groups independently, and a group consisting only of
     * "[!instanceof ...]" makes find() throw "find() needs an identifier, path or
     * instanceof filter for the first filter part" (exception 1436884196). For the same
     * reason, the positive "[instanceof ...]" filters are put first.
     *
     * If the whitelist configures exclusions only, the default node type is used as the
     * positive filter - otherwise find() would run into the very same exception.
     */
    public function flowQueryNodeTypeFilter(): string
    {
        return self::buildNodeTypeFilter($this->nodeTypeWhitelist);
    }

    /**
     * Whether a single node passes the very filter {@see flowQueryNodeTypeFilter()} expresses.
     *
     * Concatenated FlowQuery filter parts are ANDed, so a node has to be of every included node type - not of any
     * of them.
     */
    public function matchesNodeTypeWhitelist(NodeInterface $node): bool
    {
        $nodeType = $node->getNodeType();
        $partitionedNodeTypes = self::partitionNodeTypeWhitelist($this->nodeTypeWhitelist);

        foreach ($partitionedNodeTypes['excludes'] as $excludedNodeType) {
            if ($nodeType->isOfType($excludedNodeType)) {
                return false;
            }
        }

        foreach ($partitionedNodeTypes['includes'] as $includedNodeType) {
            if (!$nodeType->isOfType($includedNodeType)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why a node must not go into a content release, for the log - or NULL if it may.
     *
     * The node type is deliberately not checked here: the full enumeration adds the site node to its result without
     * passing it through the FlowQuery filter, and both enumerators would change behaviour if that were tightened
     * as a side effect. Callers which resolve nodes by identifier check {@see matchesNodeTypeWhitelist()} themselves.
     */
    public function skipReason(NodeInterface $node, NodeInterface $siteNode): ?string
    {
        // the site node has no parent inside the site, so it can never be orphaned
        if ($node !== $siteNode && self::isOrphaned($node, $siteNode)) {
            return 'orphaned';
        }

        if ($node->isHidden()) {
            return 'hidden';
        }

        return null;
    }

    /**
     * Why a node somebody named by identifier must not go into a content release, or NULL if it may.
     *
     * The node type and the pages above the node are part of the answer here, unlike in {@see skipReason()}: a node
     * which was named by hand did not come out of the FlowQuery filter and was not reached by descending from the
     * site node, so nothing else checked either of them.
     */
    public function skipReasonForNamedNode(NodeInterface $node, NodeInterface $siteNode): ?string
    {
        $skipReason = $this->skipReason($node, $siteNode);
        if ($skipReason !== null) {
            return $skipReason;
        }

        if (self::hasHiddenAncestor($node)) {
            return 'below a hidden page';
        }

        return $this->matchesNodeTypeWhitelist($node) ? null : 'not of a node type which is published';
    }

    /**
     * Whether one of the pages above the node is hidden.
     *
     * Only asked about a node named by identifier, which is resolved in a context that shows hidden nodes so that
     * "it is hidden" can be reported instead of "it does not exist". The full enumeration needs no such check: it
     * descends from the site node in a context which hides them, and therefore never reaches a document below a
     * hidden page - publishing one from a quick release would put a page live which the next full release removes
     * again. The walk goes past the site node, because a hidden site node keeps its whole site out of a full release
     * just as well.
     */
    private static function hasHiddenAncestor(NodeInterface $node): bool
    {
        $parentNode = self::getParentNodeOrNull($node);
        while ($parentNode !== null) {
            if ($parentNode->isHidden()) {
                return true;
            }
            $parentNode = self::getParentNodeOrNull($parentNode);
        }

        return false;
    }

    private static function isOrphaned(NodeInterface $node, NodeInterface $siteNode): bool
    {
        $parentNode = self::getParentNodeOrNull($node);
        while ($parentNode !== $siteNode) {
            if ($parentNode === null) {
                return true;
            }
            $parentNode = self::getParentNodeOrNull($parentNode);
        }

        return false;
    }

    /**
     * The parent of a node, or NULL where the walk up leaves the tree.
     *
     * Walking up needs findParentNode(), which belongs to TraversableNodeInterface - a different interface from the
     * NodeInterface the rest of this class works with, implemented side by side by the content repository's node
     * class. Both conversions live here so that callers compare nodes of one type, and so that "left the tree"
     * stays a NULL rather than an exception.
     */
    private static function getParentNodeOrNull(NodeInterface $node): ?NodeInterface
    {
        if (!$node instanceof TraversableNodeInterface) {
            return null;
        }

        try {
            $parentNode = $node->findParentNode();
        } catch (NodeException) {
            return null;
        }

        return $parentNode instanceof NodeInterface ? $parentNode : null;
    }

    /**
     * @param array<int, string> $nodeTypeWhitelist
     */
    private static function buildNodeTypeFilter(array $nodeTypeWhitelist): string
    {
        $partitionedNodeTypes = self::partitionNodeTypeWhitelist($nodeTypeWhitelist);

        $filterParts = [];
        foreach ($partitionedNodeTypes['includes'] as $includedNodeType) {
            $filterParts[] = '[instanceof ' . $includedNodeType . ']';
        }
        foreach ($partitionedNodeTypes['excludes'] as $excludedNodeType) {
            $filterParts[] = '[!instanceof ' . $excludedNodeType . ']';
        }

        return implode('', $filterParts);
    }

    /**
     * @param array<int, string> $nodeTypeWhitelist
     * @return array{includes: array<int, string>, excludes: array<int, string>}
     */
    private static function partitionNodeTypeWhitelist(array $nodeTypeWhitelist): array
    {
        $includes = [];
        $excludes = [];

        foreach ($nodeTypeWhitelist as $nodeType) {
            $nodeType = trim($nodeType);
            if ($nodeType === '') {
                continue;
            }
            if ($nodeType[0] === '!') {
                $excludes[] = substr($nodeType, 1);
                continue;
            }
            $includes[] = $nodeType;
        }

        if ($includes === []) {
            $includes[] = self::DEFAULT_NODE_TYPE;
        }

        return ['includes' => $includes, 'excludes' => $excludes];
    }
}
