<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumerators;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumeratorInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;

class DefaultEnumerator implements DocumentEnumeratorInterface
{
    public function __construct(array $options = [])
    {
    }

    public function enumerateDocumentNode(NodeInterface $documentNode): iterable
    {
        return [
            EnumeratedNode::fromNode($documentNode),
        ];
    }
}
