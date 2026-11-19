<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeEnumeration\Domain\Dto;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests that an enumerated node survives the round trip through Redis, which stores it as JSON between enumeration
 * and rendering.
 */
final class EnumeratedNodeTest extends TestCase
{
    public function testTheNodeAddressSurvivesTheRoundTripThroughJson(): void
    {
        $node = self::node();

        $enumeratedNode = EnumeratedNode::fromJsonString(json_encode(
            EnumeratedNode::fromNode($node, ['@format' => 'json'])->withRendererId('htmlViaFusion'),
            JSON_THROW_ON_ERROR,
        ));

        self::assertTrue($enumeratedNode->getNodeAddress()->equals(NodeAddress::fromNode($node)));
        self::assertSame('page', $enumeratedNode->getNodeIdentifier());
        self::assertSame('Neos.Neos:Document', $enumeratedNode->getNodeTypeName());
        self::assertSame(['@format' => 'json'], $enumeratedNode->getArguments());
        self::assertSame('htmlViaFusion', $enumeratedNode->rendererId);
    }

    public function testTheDimensionsAreTheCoordinatesOfTheDimensionSpacePoint(): void
    {
        // DocumentNodeCacheKey builds the "doc--" key from them, which CacheUrlMappingAspect writes the same way
        $enumeratedNode = EnumeratedNode::fromNode(self::node());

        self::assertSame(['language' => 'de', 'market' => 'ch'], $enumeratedNode->getDimensionsFromContextPath());
        self::assertSame('live', $enumeratedNode->getWorkspaceNameFromContextPath());
    }

    public function testAStringWhichIsNoJsonObjectIsRejected(): void
    {
        $this->expectException(Exception::class);

        EnumeratedNode::fromJsonString('"no object"');
    }

    private static function node(): Node
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dimensions = ['language' => 'de', 'market' => 'ch'];

        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::fromArray($dimensions),
            NodeAggregateId::fromString('page'),
            OriginDimensionSpacePoint::fromArray($dimensions),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString('Neos.Neos:Document'),
            new PropertyCollection(
                SerializedPropertyValues::fromArray([]),
                new PropertyConverter(new Serializer([], [])),
            ),
            null,
            NodeTags::create(SubtreeTags::createEmpty(), SubtreeTags::createEmpty()),
            Timestamps::create($now, $now, null, null),
            VisibilityConstraints::createEmpty(),
        );
    }
}
