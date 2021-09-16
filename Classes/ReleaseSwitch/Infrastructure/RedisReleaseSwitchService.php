<?php

namespace Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
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

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    public function switchContentRelease(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger)
    {
        $redis = $this->redisClient->getRedis($redisInstanceIdentifier);
        $current = $redis->get('contentStore:current');

        // validation checks
        // we don't check for errors here (again) as we do not reach this stage if there were errors before
        if (!in_array($contentReleaseIdentifier->getIdentifier(), $redis->zRevRangeByLex('contentStore:registeredReleases', '+', '-'))) {
            $contentReleaseLogger->error('Content release identifier ' . $contentReleaseIdentifier->getIdentifier() . ' is not listed in current releases thus we do not switch.');
            return;
        }

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);
        $hasError = false;
        foreach ($redisKeyPostfixesForEachRelease->getRequiredKeys() as $requiredPostfix) {
            if ($requiredPostfix->shouldTransfer()) {
                $key = $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, $requiredPostfix->getRedisKeyPostfix());
                if (!$redis->exists($key)) {
                    $contentReleaseLogger->error('Required redis key ' . $key . ' does not exist for release thus we do not switch.');
                    $hasError = true;
                }
            }
        }
        if ($hasError === true) {
            $contentReleaseLogger->error('ABORTING directly before switch because final key validation failed.');
            exit(1);
            return;
        }

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
