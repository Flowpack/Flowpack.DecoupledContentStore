<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeEnumeration\Domain\Service;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\NodeContextCombinator;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Tests how a quick release finds the variants of a node somebody named by its identifier.
 */
final class NodeContextCombinatorTest extends TestCase
{
    public function testAnIdentifierWhichIsNoNodeAggregateIdYieldsNothing(): void
    {
        $contentRepositoryRegistry = $this->createMock(ContentRepositoryRegistry::class);
        $contentRepositoryRegistry->expects(self::never())->method('get');

        $nodeContextCombinator = new NodeContextCombinator(
            $this->createMock(SiteRepository::class),
            $contentRepositoryRegistry,
            false,
        );

        self::assertSame([], iterator_to_array($nodeContextCombinator->nodeVariants('not a valid id!')));
    }

    public function testOnlyTheDimensionsTheNodeExistsInAreYielded(): void
    {
        $german = DimensionSpacePoint::fromArray(['language' => 'de']);
        $english = DimensionSpacePoint::fromArray(['language' => 'en']);
        $germanNode = self::createStub(Node::class);

        $nodeContextCombinator = $this->buildNodeContextCombinator(
            WorkspaceName::forLive(),
            [$german, $english],
            static fn(DimensionSpacePoint $dimensionSpacePoint): ?Node => (
                $dimensionSpacePoint->equals($german) ? $germanNode : null
            ),
        );

        self::assertSame([$germanNode], iterator_to_array($nodeContextCombinator->nodeVariants('page'), false));
    }

    public function testHiddenNodesAreFoundRegardlessOfRecurseHiddenContent(): void
    {
        // "it is hidden" is the answer somebody naming the node needs; a subgraph hiding it could only say "not found"
        $contentRepositoryRegistry = $this->buildContentRepositoryRegistry(
            WorkspaceName::forLive(),
            [DimensionSpacePoint::createWithoutDimensions()],
            fn(): ?Node => null,
            static function (VisibilityConstraints $visibilityConstraints): void {
                self::assertEquals(NeosVisibilityConstraints::excludeRemoved(), $visibilityConstraints);
            },
        );

        $nodeContextCombinator = new NodeContextCombinator(
            $this->createMock(SiteRepository::class),
            $contentRepositoryRegistry,
            false,
        );

        iterator_to_array($nodeContextCombinator->nodeVariants('page'));
    }

    public function testTheGivenWorkspaceIsSearched(): void
    {
        $nodeContextCombinator = $this->buildNodeContextCombinator(
            WorkspaceName::fromString('review'),
            [DimensionSpacePoint::createWithoutDimensions()],
            fn(): ?Node => null,
        );

        self::assertSame([], iterator_to_array($nodeContextCombinator->nodeVariants('page', 'review')));
    }

    /**
     * @param array<int, DimensionSpacePoint> $dimensionSpacePoints
     * @param callable(DimensionSpacePoint): ?Node $findNode
     */
    private function buildNodeContextCombinator(
        WorkspaceName $expectedWorkspaceName,
        array $dimensionSpacePoints,
        callable $findNode,
    ): NodeContextCombinator {
        return new NodeContextCombinator(
            $this->createMock(SiteRepository::class),
            $this->buildContentRepositoryRegistry($expectedWorkspaceName, $dimensionSpacePoints, $findNode),
            false,
        );
    }

    /**
     * @param array<int, DimensionSpacePoint> $dimensionSpacePoints
     * @param callable(DimensionSpacePoint): ?Node $findNode
     * @param ?callable(VisibilityConstraints): void $checkVisibilityConstraints
     */
    private function buildContentRepositoryRegistry(
        WorkspaceName $expectedWorkspaceName,
        array $dimensionSpacePoints,
        callable $findNode,
        ?callable $checkVisibilityConstraints = null,
    ): ContentRepositoryRegistry {
        $contentGraph = $this->createMock(ContentGraphInterface::class);
        $contentGraph
            ->method('getSubgraph')
            ->willReturnCallback(function (
                DimensionSpacePoint $dimensionSpacePoint,
                VisibilityConstraints $visibilityConstraints,
            ) use ($findNode, $checkVisibilityConstraints): ContentSubgraphInterface {
                if ($checkVisibilityConstraints !== null) {
                    $checkVisibilityConstraints($visibilityConstraints);
                }
                $subgraph = $this->createMock(ContentSubgraphInterface::class);
                $subgraph->method('findNodeById')->willReturn($findNode($dimensionSpacePoint));
                return $subgraph;
            });

        $variationGraph = $this->createMock(InterDimensionalVariationGraph::class);
        $variationGraph
            ->method('getDimensionSpacePoints')
            ->willReturn(DimensionSpacePointSet::fromArray($dimensionSpacePoints));

        $contentRepository = $this->createMock(ContentRepository::class);
        $contentRepository
            ->method('getContentGraph')
            ->with(self::callback(
                static fn(WorkspaceName $workspaceName): bool => $workspaceName->equals($expectedWorkspaceName),
            ))
            ->willReturn($contentGraph);
        $contentRepository->method('getVariationGraph')->willReturn($variationGraph);

        $contentRepositoryRegistry = $this->createMock(ContentRepositoryRegistry::class);
        $contentRepositoryRegistry->method('get')->willReturn($contentRepository);

        return $contentRepositoryRegistry;
    }
}
