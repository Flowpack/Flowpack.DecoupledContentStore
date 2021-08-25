<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Extensibility\ContentReleaseWriters;

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
     * @var \Neos\Fusion\Core\Cache\ContentCache
     */
    protected $contentCache;

    public function processRenderedDocument(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache, ContentReleaseLogger $logger): void
    {
        $rootKey = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'content')->toString();
        $rootMetadataKey = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'metadata')->toString();

        $this->redisClientManager->getPrimaryRedis()->hSet($contentReleaseIdentifier->redisKey('data'), $renderedDocumentFromContentCache->getLegacyUrlKey(), $rootKey);
        $this->redisClientManager->getPrimaryRedis()->hSet($contentReleaseIdentifier->redisKey('data'), $rootKey, $renderedDocumentFromContentCache->getFullContent());

        $this->redisClientManager->getPrimaryRedis()->hSet($contentReleaseIdentifier->redisKey('data'), $renderedDocumentFromContentCache->getLegacyMetadataKey(), $rootMetadataKey);
        $this->redisClientManager->getPrimaryRedis()->hSet($contentReleaseIdentifier->redisKey('data'), $rootMetadataKey, json_encode($renderedDocumentFromContentCache->getMetadata()));
    }

}
