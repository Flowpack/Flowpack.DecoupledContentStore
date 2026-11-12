<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumerators;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentEnumeratorInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

class DefaultEnumerator implements DocumentEnumeratorInterface
{
    public function __construct(array $options = []) {}

    public function enumerateDocumentNode(Node $documentNode): iterable
    {
        return [
            EnumeratedNode::fromNode($documentNode),
        ];
    }
}
