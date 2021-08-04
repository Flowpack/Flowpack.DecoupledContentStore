<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;

/**
 * @Flow\Scope("singleton")
 */
class RedisContentReleaseWriter
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    public function writeRenderedDocumentsToContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, string $url, string $compressedContent): void
    {
        $this->redisClientManager->getPrimaryRedis()->hSet($contentReleaseIdentifier->redisKey('renderedDocuments'), $url, $compressedContent);
    }

}