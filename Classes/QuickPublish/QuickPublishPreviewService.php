<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\DocumentNodeFilter;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\NodeContextCombinator;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\QuickPublishPreviewRow;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * Resolves the node identifiers of a quick release into what the confirmation page shows.
 *
 * It answers the same questions the enumerator will answer later, through the same
 * {@see DocumentNodeFilter::skipReasonForNamedNode()} - so what the page says will not be published is exactly what
 * the pipeline then skips.
 */
#[Flow\Scope('singleton')]
final class QuickPublishPreviewService
{
    #[Flow\Inject]
    protected NodeContextCombinator $nodeContextCombinator;

    #[Flow\Inject]
    protected DocumentNodeFilter $documentNodeFilter;

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @return array<int, QuickPublishPreviewRow> one row per dimension variant of every given identifier
     */
    public function preview(NodeIdentifiers $nodeIdentifiers, ControllerContext $controllerContext): array
    {
        $rows = [];

        foreach ($nodeIdentifiers as $nodeIdentifier) {
            $nodeFound = false;

            foreach ($this->nodeContextCombinator->nodeVariants($nodeIdentifier) as $node) {
                $nodeFound = true;
                $rows[] = QuickPublishPreviewRow::forNode(
                    $nodeIdentifier,
                    $this->nodeLabelGenerator->getLabel($node),
                    $this->contentRepositoryRegistry->subgraphForNode($node)
                        ->retrieveNodePath($node->aggregateId)
                        ->serializeToString(),
                    self::describeDimensions($node),
                    $node->nodeTypeName->value,
                    $this->backendUri($node, $controllerContext),
                    $this->documentNodeFilter->skipReasonForNamedNode($node),
                );
            }

            if (!$nodeFound) {
                $rows[] = QuickPublishPreviewRow::forNodeWhichCannotBeFound($nodeIdentifier);
            }
        }

        return $rows;
    }

    /**
     * @param array<int, QuickPublishPreviewRow> $rows
     */
    public function countPublishedRows(array $rows): int
    {
        return count(array_filter($rows, static fn(QuickPublishPreviewRow $row): bool => $row->isPublished()));
    }

    private function backendUri(Node $node, ControllerContext $controllerContext): string
    {
        return $controllerContext->getUriBuilder()
            ->reset()
            ->uriFor('index', ['node' => NodeAddress::fromNode($node)->toJson()], 'Backend', 'Neos.Neos.Ui');
    }

    private static function describeDimensions(Node $node): string
    {
        $dimensions = [];
        foreach ($node->dimensionSpacePoint->coordinates as $dimensionName => $dimensionValue) {
            $dimensions[] = $dimensionName . ': ' . $dimensionValue;
        }

        return implode(' | ', $dimensions);
    }
}
