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
    private int $limit;

    public function __construct(
        array $options = []
    ) {
        $this->limit = $options['limit'] ?? throw new \InvalidArgumentException('Missing limit option');
    }
    public function enumerateDocumentNode(NodeInterface $documentNode): iterable
    {
        if ($this->i++ >= $this->limit) {
            return [];
        }

        return [
            EnumeratedNode::fromNode($documentNode),
        ];
    }
}
