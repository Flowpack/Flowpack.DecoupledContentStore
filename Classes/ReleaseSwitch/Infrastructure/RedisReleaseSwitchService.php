<?php

namespace Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
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

    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    public function switchContentRelease(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger)
    {
        $redis = $this->redisClient->getRedis($redisInstanceIdentifier);
        $current = $redis->get('contentStore:current');
        $redis->set('contentStore:current', $contentReleaseIdentifier->getIdentifier());
        $releaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        $this->redisContentReleaseService->setContentReleaseMetadata($contentReleaseIdentifier, $releaseMetadata->withSwitchTime(new \DateTimeImmutable()), $redisInstanceIdentifier);

        $contentReleaseLogger->info(sprintf('Switched redis %s from content release %s to %s', $redisInstanceIdentifier->getIdentifier(), $current, $contentReleaseIdentifier->getIdentifier()));
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
