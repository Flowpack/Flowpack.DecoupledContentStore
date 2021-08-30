<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters;

use Flowpack\DecoupledContentStore\Core\RedisKeyService;
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

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

    public function processRenderedDocument(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void
    {
        $compressedContent = gzencode($renderedDocumentFromContentCache->getFullContent(), 9);
        $redis = $this->redisClientManager->getPrimaryRedis();
        $redis->hSet($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderedDocuments'), $renderedDocumentFromContentCache->getUrl(), $compressedContent);

        // Published URLs, lexicographically sorted
        // we use the same score "0" for all URLs, this way, they are lexicographically sorted
        // as explained in https://redis.io/topics/data-types-intro#lexicographical-scores
        $redis->zAdd($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'meta:urls'), 0, $renderedDocumentFromContentCache->getUrl());
    }
}
