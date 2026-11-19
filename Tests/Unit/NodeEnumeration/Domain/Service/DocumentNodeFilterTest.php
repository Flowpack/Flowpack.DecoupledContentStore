<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeEnumeration\Domain\Service;

use DateTimeImmutable;
use DateTimeZone;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\DocumentNodeFilter;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Serializer\Serializer;

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
final class DocumentNodeFilterTest extends TestCase
{
    /**
     * @param array<int, string> $nodeTypeWhitelist the setting as it arrives from the YAML configuration
     */
    private static function buildNodeTypeFilter(array $nodeTypeWhitelist): string
    {
        $method = new ReflectionMethod(DocumentNodeFilter::class, 'buildNodeTypeFilter');
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
            self::buildNodeTypeFilter(['Neos.Neos:Document', '!Neos.Neos:Shortcut']),
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
            '!My.Package:Baz',
        ]);

        self::assertStringNotContainsString(',', $filter);
        self::assertSame(
            '[instanceof Neos.Neos:Document]'
            . '[!instanceof Neos.Neos:Shortcut]'
            . '[!instanceof My.Package:Bar]'
            . '[!instanceof My.Package:Baz]',
            $filter,
        );
    }

    public function testPositiveFiltersAreOrderedFirstEvenIfConfiguredAfterExclusions(): void
    {
        // find() also throws if the combined filter STARTS with "[!instanceof ...]",
        // so positive filters must be ordered first regardless of the configured order.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['!Neos.Neos:Shortcut', 'Neos.Neos:Document']),
        );
    }

    public function testSurroundingWhitespaceOfConfiguredEntriesIsIgnored(): void
    {
        // The "!" is detected after trimming, so a padded exclusion still excludes.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter([' Neos.Neos:Document ', "\t!Neos.Neos:Shortcut\n"]),
        );
    }

    public function testEmptyEntriesAreSkipped(): void
    {
        self::assertSame(
            '[instanceof Neos.Neos:Document]',
            self::buildNodeTypeFilter(['Neos.Neos:Document', '', '   ']),
        );
    }

    public function testExclusionOnlyWhitelistFallsBackToTheDefaultNodeType(): void
    {
        // Without a positive filter, find() would throw exception 1436884196.
        self::assertSame(
            '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]',
            self::buildNodeTypeFilter(['!Neos.Neos:Shortcut']),
        );
    }

    public function testEmptyWhitelistFallsBackToTheDefaultNodeType(): void
    {
        self::assertSame('[instanceof Neos.Neos:Document]', self::buildNodeTypeFilter([]));
    }

    public function testADocumentBelowAHiddenPageIsNotPublished(): void
    {
        // the full enumeration descends in a subgraph which hides them and never reaches such a document, so a quick
        // release publishing it would put a page live which the next full release removes again
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::create(NeosSubtreeTag::disabled()));

        self::assertSame('below a hidden page', $this->visibilitySkipReasonForNamedNode($node));
    }

    public function testADocumentBelowVisiblePagesIsPublished(): void
    {
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::createEmpty());

        self::assertNull($this->visibilitySkipReasonForNamedNode($node));
    }

    public function testADocumentBelowAHiddenPageIsPublishedWhereTheFullEnumerationRecursesIntoOne(): void
    {
        // with "recurseHiddenContent" the full enumeration reaches such a document, so skipping it in a quick
        // release would be the same mismatch the other way round
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::create(NeosSubtreeTag::disabled()));

        self::assertNull($this->visibilitySkipReasonForNamedNode($node, true));
    }

    public function testAHiddenPageIsNotPublishedEvenWhereTheFullEnumerationRecursesIntoIt(): void
    {
        $node = self::node(SubtreeTags::create(NeosSubtreeTag::disabled()), SubtreeTags::createEmpty());

        self::assertSame('hidden', $this->visibilitySkipReasonForNamedNode($node, true));
    }

    public function testTheFullEnumerationPublishesADocumentBelowAHiddenPage(): void
    {
        // it only gets there with "recurseHiddenContent", which is the very setting that asks for it
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::create(NeosSubtreeTag::disabled()));

        self::assertNull($this->buildDocumentNodeFilter()->skipReason($node));
    }

    public function testANodeOfAPublishedNodeTypeIsPublished(): void
    {
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::createEmpty());

        self::assertNull($this->buildDocumentNodeFilter(false, ['Neos.Neos:Document'])->skipReasonForNamedNode($node));
    }

    public function testANodeOfAnExcludedNodeTypeIsNotPublished(): void
    {
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::createEmpty());

        self::assertSame(
            'not of a node type which is published',
            $this->buildDocumentNodeFilter(false, ['Neos.Neos:Document', 'Neos.Neos:Shortcut'])
                ->skipReasonForNamedNode($node),
        );
    }

    public function testANodeOfAnUnknownNodeTypeIsNotPublished(): void
    {
        // e.g. a node type which was removed from the configuration while its nodes still exist
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::createEmpty());

        self::assertSame(
            'not of a node type which is published',
            $this->buildDocumentNodeFilter(false, null)->skipReasonForNamedNode($node),
        );
    }

    public function testANodeMustBeOfEveryIncludedNodeType(): void
    {
        // concatenated FlowQuery filter parts are ANDed
        $node = self::node(SubtreeTags::createEmpty(), SubtreeTags::createEmpty());
        $documentNodeFilter = $this->buildDocumentNodeFilter(
            false,
            ['Neos.Neos:Document'],
            ['Neos.Neos:Document', 'My.Package:Page'],
        );

        self::assertFalse($documentNodeFilter->matchesNodeTypeWhitelist($node));
    }

    public function testVisibilityIsCheckedBeforeTheNodeType(): void
    {
        $node = self::node(SubtreeTags::create(NeosSubtreeTag::disabled()), SubtreeTags::createEmpty());

        self::assertSame('hidden', $this->buildDocumentNodeFilter(false, null)->skipReasonForNamedNode($node));
    }

    private function visibilitySkipReasonForNamedNode(Node $node, bool $recurseHiddenContent = false): ?string
    {
        return $this->buildDocumentNodeFilter($recurseHiddenContent)->skipReasonForNamedNode($node);
    }

    /**
     * @param array<int, string>|null $superTypesOfTheNodeType the node types the tested node is of, NULL if its
     *     node type is unknown to the content repository
     * @param array<int, string> $nodeTypeWhitelist
     */
    private function buildDocumentNodeFilter(
        bool $recurseHiddenContent = false,
        ?array $superTypesOfTheNodeType = ['Neos.Neos:Document'],
        array $nodeTypeWhitelist = ['Neos.Neos:Document', '!Neos.Neos:Shortcut'],
    ): DocumentNodeFilter {
        $nodeType = null;
        if ($superTypesOfTheNodeType !== null) {
            $nodeType = $this->createMock(NodeType::class);
            $nodeType
                ->method('isOfType')
                ->willReturnCallback(static fn(string $nodeTypeName): bool => (
                    in_array($nodeTypeName, $superTypesOfTheNodeType, true)
                ));
        }
        $nodeTypeManager = $this->createMock(NodeTypeManager::class);
        $nodeTypeManager->method('getNodeType')->willReturn($nodeType);
        $contentRepository = $this->createMock(ContentRepository::class);
        $contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);
        $contentRepositoryRegistry = $this->createMock(ContentRepositoryRegistry::class);
        $contentRepositoryRegistry->method('get')->willReturn($contentRepository);

        return new DocumentNodeFilter($contentRepositoryRegistry, $nodeTypeWhitelist, $recurseHiddenContent);
    }

    /**
     * @param SubtreeTags $tags set on the node itself
     * @param SubtreeTags $inheritedTags set on one of the nodes above it
     */
    private static function node(SubtreeTags $tags, SubtreeTags $inheritedTags): Node
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateId::fromString('node'),
            OriginDimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString('Neos.Neos:Document'),
            new PropertyCollection(
                SerializedPropertyValues::fromArray([]),
                new PropertyConverter(new Serializer([], [])),
            ),
            null,
            NodeTags::create($tags, $inheritedTags),
            Timestamps::create($now, $now, null, null),
            VisibilityConstraints::createEmpty(),
        );
    }
}
