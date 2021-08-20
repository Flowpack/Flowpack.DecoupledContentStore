<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters;

use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriterInterface;

/**
 * Takes the fully rendered document and writes it to the content release.
 *
 * This is extensible to control the format of the content releases, and to add additional metadata.
 */
class GzipWriter implements ContentReleaseWriterInterface
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    public function processRenderedDocument(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void
    {
        $compressedContent = gzencode($renderedDocumentFromContentCache->getFullContent(), 9);
        $this->redisClientManager->getPrimaryRedis()->hSet($contentReleaseIdentifier->redisKey('data'), $renderedDocumentFromContentCache->getUrl(), $compressedContent);
    }
}
