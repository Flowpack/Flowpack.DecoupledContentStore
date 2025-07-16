<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Neos\ContentRepository\Domain\Model\NodeInterface;

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
 */
interface DocumentEnumeratorInterface
{
    public function __construct(array $options = []);

    /**
     * @return iterable<EnumeratedNode>
     */
    public function enumerateDocumentNode(NodeInterface $documentNode): iterable;
}
