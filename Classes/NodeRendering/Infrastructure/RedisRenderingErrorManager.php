<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;

/**
 * @Flow\Scope("singleton")
 */
class RedisRenderingErrorManager
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    public function registerRenderingError(ContentReleaseIdentifier $contentReleaseIdentifier, array $additionalData, \Exception $exception): void
    {
        $this->redisClientManager->getPrimaryRedis()->sAdd($contentReleaseIdentifier->redisKey('renderingErrors'), $exception->getMessage() . ' - ' . json_encode($additionalData));
    }


    public function getRenderingErrors(ContentReleaseIdentifier $contentReleaseIdentifier): array
    {
        return $this->redisClientManager->getPrimaryRedis()->sMembers($contentReleaseIdentifier->redisKey('renderingErrors'));
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier): void
    {
        $this->redisClientManager->getPrimaryRedis()->del($contentReleaseIdentifier->redisKey('renderingErrors'));
    }

}