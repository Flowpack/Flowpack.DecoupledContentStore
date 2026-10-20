<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriterInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Takes the fully rendered document and writes it to the content release.
 *
 * This is extensible to control the format of the content releases, and to add additional metadata.
 */
final class GzipWriter implements ContentReleaseWriterInterface
{
    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected RedisKeyService $redisKeyService;

    public function __construct(array $options = []) {}

    public function processRenderedDocument(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        RenderedDocumentFromContentCache $renderedDocumentFromContentCache,
        ContentReleaseLogger $logger,
    ): void {
        $compressedContent = gzencode($renderedDocumentFromContentCache->getFullContent(), 9);
        $redis = $this->redisClientManager->getPrimaryRedis();
        $redis->hSet(
            $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderedDocuments'),
            $renderedDocumentFromContentCache->getUrl(),
            $compressedContent,
        );

        // Published URLs, lexicographically sorted
        // we use the same score "0" for all URLs, this way, they are lexicographically sorted
        // as explained in https://redis.io/topics/data-types-intro#lexicographical-scores
        $redis->zAdd(
            $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'meta:urls'),
            0,
            $renderedDocumentFromContentCache->getUrl(),
        );
    }
}
