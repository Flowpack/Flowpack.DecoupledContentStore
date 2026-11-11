<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumerators;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumeratorInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * An enumerator which only renders the first N pages. very useful for interactive testing.
 * NOT useful for production.
 */
class LimitEnumerator implements DocumentEnumeratorInterface
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    private int $i = 0;
    private ?int $limit;
    private ?string $uriPathSegmentFilter;
    private ?string $nodePathSegmentFilter;

    public function __construct(
        array $options = [],
    ) {
        $this->limit = $options['limit'] ?? null;
        $this->uriPathSegmentFilter = $options['uriPathSegmentFilter'] ?? null;
        $this->nodePathSegmentFilter = $options['nodePathSegmentFilter'] ?? null;
    }

    public function enumerateDocumentNode(Node $documentNode): iterable
    {
        if (
            $this->uriPathSegmentFilter !== null
            && str_contains($documentNode->getProperty('uriPathSegment'), $this->uriPathSegmentFilter) === false
        ) {
            return [];
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($documentNode);

        if (
            $this->nodePathSegmentFilter !== null
            && str_contains(
                $subgraph->retrieveNodePath($documentNode->aggregateId)->serializeToString(),
                $this->nodePathSegmentFilter,
            ) === false
        ) {
            return [];
        }

        // NOTE: Limiting must come LAST, after all other constraints have been evaluated (because we want
        // only filtered nodes from the other conditions to be counted against the limit)
        if ($this->limit !== null) {
            if ($this->i++ >= $this->limit) {
                return [];
            }
        }

        return [
            EnumeratedNode::fromNode($documentNode),
        ];
    }
}
