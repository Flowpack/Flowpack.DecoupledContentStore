<?php

namespace Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;

/**
 * @Flow\Scope("singleton")
 */
class RedisReleaseSwitchService
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClient;

    public function switchContentRelease(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger)
    {
        $redis = $this->redisClient->getRedis($redisInstanceIdentifier);
        $current = $redis->get('contentStore:current');
        $redis->set('contentStore:current', $contentReleaseIdentifier->getIdentifier());

        $contentReleaseLogger->info(sprintf('Switched redis %s from content release %s to %s', $redisInstanceIdentifier->getIdentifier(), $current, $contentReleaseIdentifier));
    }

    public function getCurrentRelease(RedisInstanceIdentifier $redisInstanceIdentifier): ?ContentReleaseIdentifier
    {
        $redis = $this->redisClient->getRedis($redisInstanceIdentifier);
        $current = $redis->get('contentStore:current');
        if ($current) {
            return ContentReleaseIdentifier::fromString($current);
        }
        return null;
    }
}