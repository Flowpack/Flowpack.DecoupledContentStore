<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentRenderers;

use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheKey;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\DocumentRendererInterface;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisContentCacheReader;
use Flowpack\DecoupledContentStore\NodeRendering\Render\DocumentRenderer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

class FusionHtmlRenderer implements DocumentRendererInterface
{
    /**
     * @Flow\Inject
     * @var RedisContentCacheReader
     */
    protected $redisContentCacheReader;

    /**
     * @Flow\Inject
     * @var DocumentRenderer
     */
    protected $documentRenderer;

    public function tryToExtractRenderingForEnumeratedNodeFromContentCache(EnumeratedNode $enumeratedNode): RenderedDocumentFromContentCache
    {
        return $this->redisContentCacheReader->tryToExtractRenderingForEnumeratedNodeFromContentCache(DocumentNodeCacheKey::fromEnumeratedNode(
            $enumeratedNode
        ));
    }

    public function renderDocumentNodeVariant(
        NodeInterface $node,
        EnumeratedNode $enumeratedNode,
        ContentReleaseLogger $contentReleaseLogger
    ): void {
        $this->documentRenderer->renderDocumentNodeVariant(
            $node,
            $enumeratedNode->getArguments(),
            $contentReleaseLogger
        );
    }
}
