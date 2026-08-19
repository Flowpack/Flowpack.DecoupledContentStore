<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\QuickPublish\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Exception\InvalidReleaseException;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;
use Redis;

/**
 * Takes the content of a finished content release over into a new one, so that only the documents which actually
 * changed have to be rendered again.
 *
 * Both releases live in the same Redis instance, so this uses the native COPY command: the data never travels to
 * the client and back, which is what makes copying a release cheaper than rendering it.
 */
#[Flow\Scope('singleton')]
final class RedisReleaseCopyService
{
    /**
     * COPY exists since Redis 6.2, while the rest of this package runs on older servers as well.
     */
    private const MINIMUM_REDIS_VERSION = '6.2.0';

    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected RedisKeyService $redisKeyService;

    #[Flow\Inject]
    protected RedisContentReleaseService $redisContentReleaseService;

    /**
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration('redisKeyPostfixesForEachRelease')]
    protected array $redisKeyPostfixesForEachReleaseConfiguration;

    /**
     * @throws Exception if the source release cannot be built upon, or the server is too old for COPY
     */
    public function copyReleaseWithin(
        RedisInstanceIdentifier $redisInstanceIdentifier,
        ContentReleaseIdentifier $sourceContentReleaseIdentifier,
        ContentReleaseIdentifier $targetContentReleaseIdentifier,
        ContentReleaseLogger $contentReleaseLogger,
    ): void {
        if ($sourceContentReleaseIdentifier->equals($targetContentReleaseIdentifier)) {
            throw new InvalidReleaseException(
                sprintf(
                    'Cannot copy content release %s onto itself.',
                    $sourceContentReleaseIdentifier->getIdentifier(),
                ),
                1786953585,
            );
        }

        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $this->assertServerSupportsCopy($redis);
        $this->assertSourceReleaseCanBeBuiltUpon($redis, $redisInstanceIdentifier, $sourceContentReleaseIdentifier);

        $contentReleaseLogger->info(sprintf(
            'Copying content release %s to %s within redis %s',
            $sourceContentReleaseIdentifier->getIdentifier(),
            $targetContentReleaseIdentifier->getIdentifier(),
            $redisInstanceIdentifier->getIdentifier(),
        ));

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);
        $startTime = microtime(true);
        $copiedKeyCount = 0;

        foreach ($redisKeyPostfixesForEachRelease->getKeysToCopyOnQuickRelease() as $redisKeyPostfix) {
            $sourceKey = $this->redisKeyService->getRedisKeyForPostfix(
                $sourceContentReleaseIdentifier,
                $redisKeyPostfix->getRedisKeyPostfix(),
            );
            $targetKey = $this->redisKeyService->getRedisKeyForPostfix(
                $targetContentReleaseIdentifier,
                $redisKeyPostfix->getRedisKeyPostfix(),
            );

            if (!$redis->exists($sourceKey)) {
                $contentReleaseLogger->info('COPY: Skipping ' . $sourceKey . ', as it does not exist.');
                continue;
            }

            if ($redis->exists($targetKey)) {
                $contentReleaseLogger->warn(
                    'COPY: '
                    . $targetKey
                    . ' already exists and is replaced - '
                    . 'the release was copied into after something already wrote to it.',
                );
            }

            $keyStartTime = microtime(true);
            if ($redis->copy($sourceKey, $targetKey, ['replace' => true]) !== true) {
                throw new InvalidReleaseException(
                    'COPY: Could not copy ' . $sourceKey . ' to ' . $targetKey . '.',
                    1786953586,
                );
            }
            $copiedKeyCount++;

            $contentReleaseLogger->info(sprintf(
                'COPY: Copied key %s (time: %2.3f)',
                $targetKey,
                microtime(true) - $keyStartTime,
            ));
        }

        $contentReleaseLogger->info(sprintf(
            'COPY: Copied %d keys from content release %s (total time: %2.3f)',
            $copiedKeyCount,
            $sourceContentReleaseIdentifier->getIdentifier(),
            microtime(true) - $startTime,
        ));
    }

    /**
     * @throws Exception
     */
    private function assertServerSupportsCopy(Redis $redis): void
    {
        $serverInfo = $redis->info('server');
        $redisVersion = is_array($serverInfo) && array_key_exists('redis_version', $serverInfo)
            ? (string) $serverInfo['redis_version']
            : '';

        if ($redisVersion === '' || version_compare($redisVersion, self::MINIMUM_REDIS_VERSION, '<')) {
            throw new Exception(
                sprintf(
                    'Copying a content release needs the redis COPY command, which requires redis %s or newer. '
                    . 'This server reports version "%s".',
                    self::MINIMUM_REDIS_VERSION,
                    $redisVersion,
                ),
                1786953587,
            );
        }
    }

    /**
     * A quick release inherits everything it does not render itself, so a source release which never finished would
     * be published as if it had. The status is checked rather than assumed because an administrator can switch to an
     * arbitrary release by hand, so being the currently active release does not mean a release completed.
     *
     * @throws Exception
     */
    private function assertSourceReleaseCanBeBuiltUpon(
        Redis $redis,
        RedisInstanceIdentifier $redisInstanceIdentifier,
        ContentReleaseIdentifier $sourceContentReleaseIdentifier,
    ): void {
        $metadata = $this->redisContentReleaseService->fetchMetadataForContentRelease(
            $sourceContentReleaseIdentifier,
            $redisInstanceIdentifier,
        );

        if ($metadata === null) {
            throw new InvalidReleaseException(
                sprintf(
                    'Content release %s does not exist in redis %s, so it cannot be copied. Run a full content release '
                    . 'instead.',
                    $sourceContentReleaseIdentifier->getIdentifier(),
                    $redisInstanceIdentifier->getIdentifier(),
                ),
                1786953588,
            );
        }

        if (!$metadata->getStatus()->isSuccessful()) {
            throw new InvalidReleaseException(
                sprintf(
                    'Content release %s has the status "%s" instead of "success", so it cannot be copied. Run a full '
                    . 'content release instead.',
                    $sourceContentReleaseIdentifier->getIdentifier(),
                    $metadata->getStatus()->getStatus(),
                ),
                1786953589,
            );
        }

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);

        // only the inherited keys have to exist - the rest is built by the quick release itself. isRequired alone is
        // not enough of a filter: the other places reading it check it per transfer target, so a key an installation
        // does not write at all is switched off there with "transfer: false" and stays required here.
        foreach ($redisKeyPostfixesForEachRelease->getKeysToCopyOnQuickRelease() as $requiredPostfix) {
            if (!$requiredPostfix->isRequired()) {
                continue;
            }

            $requiredKey = $this->redisKeyService->getRedisKeyForPostfix(
                $sourceContentReleaseIdentifier,
                $requiredPostfix->getRedisKeyPostfix(),
            );
            if (!$redis->exists($requiredKey)) {
                throw new InvalidReleaseException(
                    sprintf(
                        'Required redis key %s does not exist, so content release %s cannot be copied. Run a full '
                        . 'content release instead.',
                        $requiredKey,
                        $sourceContentReleaseIdentifier->getIdentifier(),
                    ),
                    1786953590,
                );
            }
        }
    }
}
