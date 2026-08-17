<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\DocumentNodeFilter;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Service\NodeContextCombinator;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\QuickPublishPreviewRow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;

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

    /**
     * @return array<int, QuickPublishPreviewRow> one row per dimension variant of every given identifier
     */
    public function preview(NodeIdentifiers $nodeIdentifiers, ControllerContext $controllerContext): array
    {
        $rows = [];

        foreach ($nodeIdentifiers as $nodeIdentifier) {
            $nodeFound = false;

            foreach ($this->nodeContextCombinator->nodeVariantsWithSiteNode($nodeIdentifier) as [$siteNode, $node]) {
                $nodeFound = true;
                $rows[] = QuickPublishPreviewRow::forNode(
                    $nodeIdentifier,
                    $node->getLabel(),
                    $node->getPath(),
                    self::describeDimensions($node),
                    $node->getNodeType()->getName(),
                    $this->backendUri($node, $controllerContext),
                    $this->documentNodeFilter->skipReasonForNamedNode($node, $siteNode)
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

    private function backendUri(NodeInterface $node, ControllerContext $controllerContext): string
    {
        return $controllerContext->getUriBuilder()->reset()->uriFor(
            'index',
            ['node' => $node->getContextPath()],
            'Backend',
            'Neos.Neos.Ui'
        );
    }

    private static function describeDimensions(NodeInterface $node): string
    {
        $dimensions = [];
        foreach ($node->getContext()->getDimensions() as $dimensionName => $dimensionValues) {
            $dimensions[] = $dimensionName . ': ' . implode(', ', $dimensionValues);
        }

        return implode(' | ', $dimensions);
    }
}
