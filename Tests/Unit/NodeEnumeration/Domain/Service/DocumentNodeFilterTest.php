<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeEnumeration\Domain\Service;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\DocumentNodeFilter;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Exception\NodeException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the FlowQuery filter built from the "nodeRendering.nodeTypeWhitelist" setting, which is a
 * list of node types where entries prefixed with "!" are excluded:
 *
 *     nodeTypeWhitelist:
 *       - 'Neos.Neos:Document'
 *       - '!My.Package:Bar'
 *
 * ... and the checks a node named by identifier for a quick release has to pass, which the FlowQuery filter and the
 * traversal of the full enumeration answer implicitly.
 */
class DocumentNodeFilterTest extends TestCase
{
    /**
     * @param array<int, string> $nodeTypeWhitelist the setting as it arrives from the YAML configuration
     */
    private static function buildNodeTypeFilter(array $nodeTypeWhitelist): string
    {
        $method = new \ReflectionMethod(DocumentNodeFilter::class, 'buildNodeTypeFilter');
        return $method->invoke(null, $nodeTypeWhitelist);
    }

    public function testSingleNodeTypeProducesSimpleInstanceofFilter(): void
    {
        self::assertSame('[instanceof Neos.Neos:Document]', self::buildNodeTypeFilter(['Neos.Neos:Document']));
    }

    public function testNegatedNodeTypesAreCombinedWithAndInsteadOfOr(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['Neos.Neos:Document', '!Neos.Neos:Shortcut'])
        );
    }

    public function testFilterPartsAreNotJoinedByComma(): void
    {
        // FlowQuery parses comma-separated filter groups independently, so a group
        // consisting only of "[!instanceof ...]" makes find() throw
        // "find() needs an identifier, path or instanceof filter for the first filter part".
        $filter = self::buildNodeTypeFilter([
            'Neos.Neos:Document',
            '!Neos.Neos:Shortcut',
            '!My.Package:Bar',
            '!My.Package:Baz'
        ]);

        self::assertStringNotContainsString(',', $filter);
        self::assertSame(
            '[instanceof Neos.Neos:Document]'
            . '[!instanceof Neos.Neos:Shortcut]'
            . '[!instanceof My.Package:Bar]'
            . '[!instanceof My.Package:Baz]',
            $filter
        );
    }

    public function testPositiveFiltersAreOrderedFirstEvenIfConfiguredAfterExclusions(): void
    {
        // find() also throws if the combined filter STARTS with "[!instanceof ...]",
        // so positive filters must be ordered first regardless of the configured order.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['!Neos.Neos:Shortcut', 'Neos.Neos:Document'])
        );
    }

    public function testSurroundingWhitespaceOfConfiguredEntriesIsIgnored(): void
    {
        // The "!" is detected after trimming, so a padded exclusion still excludes.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter([' Neos.Neos:Document ', "\t!Neos.Neos:Shortcut\n"])
        );
    }

    public function testEmptyEntriesAreSkipped(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document]',
            self::buildNodeTypeFilter(['Neos.Neos:Document', '', '   '])
        );
    }

    public function testExclusionOnlyWhitelistFallsBackToTheDefaultNodeType(): void
    {
        // Without a positive filter, find() would throw exception 1436884196.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['!Neos.Neos:Shortcut'])
        );
    }

    public function testEmptyWhitelistFallsBackToTheDefaultNodeType(): void
    {
        self::assertSame('[instanceof Neos.Neos:Document]', self::buildNodeTypeFilter([]));
    }

    public function testADocumentBelowAHiddenPageIsNotPublished(): void
    {
        // the full enumeration descends in a context which hides them and never reaches such a document, so a quick
        // release publishing it would put a page live which the next full release removes again
        $siteNode = $this->nodeMock(false, null);
        $hiddenParentNode = $this->nodeMock(true, $siteNode);
        $node = $this->nodeMock(false, $hiddenParentNode);

        self::assertSame(
            'below a hidden page',
            $this->buildDocumentNodeFilter()->skipReasonForNamedNode($node, $siteNode)
        );
    }

    public function testADocumentBelowVisiblePagesIsPublished(): void
    {
        $siteNode = $this->nodeMock(false, null);
        $parentNode = $this->nodeMock(false, $siteNode);
        $node = $this->nodeMock(false, $parentNode);

        self::assertNull($this->buildDocumentNodeFilter()->skipReasonForNamedNode($node, $siteNode));
    }

    private function buildDocumentNodeFilter(): DocumentNodeFilter
    {
        $documentNodeFilter = new DocumentNodeFilter();

        // the setting arrives by InjectConfiguration, which no container assembles in a unit test
        $nodeTypeWhitelist = new \ReflectionProperty(DocumentNodeFilter::class, 'nodeTypeWhitelist');
        $nodeTypeWhitelist->setValue($documentNodeFilter, ['Neos.Neos:Document']);

        return $documentNodeFilter;
    }

    /**
     * The content repository's node class is mocked rather than one of the two interfaces, because the walk up needs
     * TraversableNodeInterface while everything else is NodeInterface, and that class is what implements both.
     *
     * @param Node|null $parentNode NULL where the walk up leaves the tree, as it does above the root node
     * @return Node&MockObject
     */
    private function nodeMock(bool $hidden, ?Node $parentNode): Node
    {
        $nodeType = $this->createMock(NodeType::class);
        $nodeType->method('isOfType')->willReturn(true);

        $node = $this->createMock(Node::class);
        $node->method('isHidden')->willReturn($hidden);
        $node->method('getNodeType')->willReturn($nodeType);

        if ($parentNode === null) {
            $node->method('findParentNode')->willThrowException(new NodeException());
        } else {
            $node->method('findParentNode')->willReturn($parentNode);
        }

        return $node;
    }
}
