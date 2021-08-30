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
use Ramsey\Uuid\Uuid;

/**
 * Takes the fully rendered document and writes it to the content release.
 *
 * This is extensible to control the format of the content releases, and to add additional metadata.
 */
class LegacyWriter implements ContentReleaseWriterInterface
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

    /**
     * @Flow\Inject
     * @var \Neos\Fusion\Core\Cache\ContentCache
     */
    protected $contentCache;

    public function processRenderedDocument(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void
    {
        $urlKey = $renderedDocumentFromContentCache->getLegacyUrlKey();
        $metadataUrlKey = $renderedDocumentFromContentCache->getLegacyMetadataKey();

        $rootKey = Uuid::uuid5(Uuid::NAMESPACE_DNS, $urlKey)->toString();
        $rootMetadataKey = Uuid::uuid5(Uuid::NAMESPACE_DNS, $metadataUrlKey)->toString();

        $redisDataKey = $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'data');

        $redis = $this->redisClientManager->getPrimaryRedis();

        $redis->hSet($redisDataKey, $urlKey, $rootKey);
        $redis->hSet($redisDataKey, $rootKey, $renderedDocumentFromContentCache->getFullContent());

        $redis->hSet($redisDataKey, $metadataUrlKey, $rootMetadataKey);
        $redis->hSet($redisDataKey, $rootMetadataKey, $renderedDocumentFromContentCache->getLegacyMetadataString());


        // Published URLs, lexicographically sorted
        // we use the same score "0" for all URLs, this way, they are lexicographically sorted
        // as explained in https://redis.io/topics/data-types-intro#lexicographical-scores
        $redis->zAdd($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'meta:urls'), 0, $renderedDocumentFromContentCache->getUrl());

    }

}
