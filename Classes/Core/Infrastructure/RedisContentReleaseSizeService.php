<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Neos\Flow\Annotations as Flow;

/**
 * Calculates how much memory a content release occupies in Redis.
 *
 * NOTE: this is an expensive operation, as it needs one MEMORY USAGE call per Redis key of the release.
 * For finished releases, use the pre-calculated size from ContentReleaseMetadata::getContentReleaseSize()
 * instead - it is determined once by the NodeRenderOrchestrator when the release is finished.
 *
 * @Flow\Scope("singleton")
 */
class RedisContentReleaseSizeService
{
    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @return float size of the content release in megabytes
     */
    public function calculateReleaseSize(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier $contentReleaseIdentifier): float
    {
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $allKeys = $redis->keys('contentStore:' . $contentReleaseIdentifier->getIdentifier() . ':*');
        $size = 0;

        foreach ($allKeys as $key) {
            // We need to set the `samples` option to 0 here, as the default value is 5 and specifies the number of
            // sampled nested values. With 0 all nested values are sampled.
            $size += $redis->rawCommand('memory', 'usage', $key, 'samples', '0');
        }

        // bytes are returned, convert to megabytes
        return round($size / 1000000, 2);
    }
}
