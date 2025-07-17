<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility;

use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Decide how to render a document node. Used throughout {@see NodeRenderOrchestrator} and {@see NodeRenderer}
 * for the specific rendering logic.
 *
 * If you want to create additional output formats (e.g. JSON), or you want rendering without
 * Fusion, you'll need to create a custom DocumentRenderer.
 */
interface DocumentRendererInterface
{
    public function tryToExtractRenderingForEnumeratedNodeFromContentCache(EnumeratedNode $enumeratedNode): RenderedDocumentFromContentCache;

    public function renderDocumentNodeVariant(NodeInterface $node, EnumeratedNode $enumeratedNode, ContentReleaseLogger $contentReleaseLogger): void;
}
