<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ContentReleaseSynchronizer
{
    /**
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    public function syncToTarget(RedisInstanceIdentifier $targetRedisIdentifier, ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger): void
    {
        $contentReleaseLogger->info('Syncing Content Release ' . $contentReleaseIdentifier->getIdentifier() . ' to target ' . $targetRedisIdentifier->getIdentifier());

        if ($targetRedisIdentifier->isPrimary()) {
            $contentReleaseLogger->error('Cannot sync to the primary redis (Content Release is already there).');
            exit(1);
        }

        $sourceRedis = $this->redisClientManager->getPrimaryRedis();
        $targetRedis = $this->redisClientManager->getRedis($targetRedisIdentifier);

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);

        foreach ($redisKeyPostfixesForEachRelease->getAllEnabled() as $redisKeyPostfix) {
            $redisKey = $contentReleaseIdentifier->redisKey($redisKeyPostfix->getRedisKeyPostfix());
            if ($redisKeyPostfix->isRequired()) {
                if (!$sourceRedis->exists($redisKey)) {
                    $contentReleaseLogger->error('Required key  ' . $redisKey . ' does not exist.');
                    exit(1);
                }

                // TODO Continue here
            }
        }
    }
}