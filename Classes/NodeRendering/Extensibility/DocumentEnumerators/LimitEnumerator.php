<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumerators;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumeratorInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * An enumerator which only renders the first N pages. very useful for interactive testing.
 * NOT useful for production.
 */
class LimitEnumerator implements DocumentEnumeratorInterface
{
    protected int $i = 0;
    private ?int $limit = null;
    private ?string $uriPathSegmentFilter = null;

    public function __construct(
        array $options = []
    ) {
        $this->limit = $options['limit'] ?? null;
        $this->uriPathSegmentFilter = $options['uriPathSegmentFilter'] ?? null;
    }
    public function enumerateDocumentNode(NodeInterface $documentNode): iterable
    {
        if ($this->uriPathSegmentFilter !== null) {
            if (str_contains($documentNode->getProperty('uriPathSegment'), $this->uriPathSegmentFilter) === false) {
                return [];
            }
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
