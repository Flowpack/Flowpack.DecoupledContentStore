<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Adjust document enumeration.
 *
 * The Document Enumeration decides for every Document Node what variants should appear
 * in the resulting Content Release.
 *
 * It should return an array of {@see EnumeratedNode} objects.
 *
 * This can be used to:
 * - render document nodes in different variants, e.g. formats
 * - use different renderers (configured via Flowpack.DecoupledContentStore.extensions.documentRenderers.[...])
 * - decide at runtime which (document) nodes should be part of a content release and which not.
 *
 * Implementations are instantiated with `new`, receiving the configured `enumeratorOptions` array as the first
 * constructor argument.
 */
interface DocumentEnumeratorInterface
{
    /**
     * @return iterable<EnumeratedNode>
     */
    public function enumerateDocumentNode(Node $documentNode): iterable;
}
