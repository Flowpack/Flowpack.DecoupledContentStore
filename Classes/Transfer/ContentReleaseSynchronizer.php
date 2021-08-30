<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ContentReleaseSynchronizer
{
    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

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

        foreach ($redisKeyPostfixesForEachRelease->getKeysToTransfer() as $redisKeyPostfix) {
            $redisKey = $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, $redisKeyPostfix->getRedisKeyPostfix());
            $contentReleaseLogger->info($redisKey);
            if ($redisKeyPostfix->isRequired() && !$sourceRedis->exists($redisKey)) {
                $contentReleaseLogger->error('Required key  ' . $redisKey . ' does not exist.');
                exit(1);
            }

            if ($redisKeyPostfix->hasTransferModeHashIncremental()) {
                $this->transferHashKeyIncrementally($sourceRedis, $targetRedis, $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, $redisKeyPostfix->getRedisKeyPostfix()), $contentReleaseLogger);
            } else {
                $this->transferKey($sourceRedis, $targetRedis, $this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, $redisKeyPostfix->getRedisKeyPostfix()), $contentReleaseLogger);
            }

        }
    }

    /**
     * Transfer a single Redis key from source to destination redis
     *
     * @param string $keyToTransfer
     */
    protected function transferKey(\Redis $sourceRedis, \Redis $targetRedis, string $keyToTransfer, ContentReleaseLogger $contentReleaseLogger)
    {
        $contentReleaseLogger->debug('SYNC: Attempting to transfer ' . $keyToTransfer);

        if (!$sourceRedis->exists($keyToTransfer)) {
            $contentReleaseLogger->info('SYNC: Skipping ' . $keyToTransfer . ', as it does not exist on the source side');
            return;
        }

        if ($targetRedis->exists($keyToTransfer)) {
            $contentReleaseLogger->warn('SYNC: Skipping ' . $keyToTransfer . ', as it DOES exist on the target side (and we do not override!)');
            return;
        }

        $startTime = microtime(true);
        $serializedValue = $sourceRedis->dump($keyToTransfer);
        $exportTime = microtime(true);
        $targetRedis->restore($keyToTransfer, 0, $serializedValue);
        $importTime = microtime(true);

        $contentReleaseLogger->info(sprintf(
            'SYNC: Transferred key %s (size: %d, export time: %2.3f, import time: %2.3f)',
            $keyToTransfer,
            strlen($serializedValue),
            $exportTime - $startTime,
            $importTime - $exportTime
        ));
    }

    protected function transferHashKeyIncrementally(\Redis $sourceRedis, \Redis $targetRedis, string $keyToTransfer, ContentReleaseLogger $contentReleaseLogger)
    {
        $contentReleaseLogger->debug('SYNC: (INCREMENTAL) Attempting to transfer ' . $keyToTransfer);

        if (!$sourceRedis->exists($keyToTransfer)) {
            $contentReleaseLogger->info('SYNC: (INCREMENTAL) Skipping ' . $keyToTransfer . ', as it does not exist on the source side');
            return;
        }
        if ($targetRedis->exists($keyToTransfer)) {
            $contentReleaseLogger->warn('SYNC: (INCREMENTAL) WARNING: ' . $keyToTransfer . ', exists on the target side; we try to copy all values into it.');
        }

        if ($sourceRedis->type($keyToTransfer) !== \Redis::REDIS_HASH) {
            $contentReleaseLogger->error('SYNC: (INCREMENTAL) !!! transferHashKeyIncrementally should only be used with hashes, but ' . $keyToTransfer . ' is of type ' . $sourceRedis->type($keyToTransfer));
            throw new \RuntimeException('!!! transferHashKeyIncrementally should only be used with hashes, but ' . $keyToTransfer . ' is of type ' . $sourceRedis->type($keyToTransfer));
        }

        $expectedNumberOfHashItems = $sourceRedis->hLen($keyToTransfer);

        // Don't ever return an empty array until we're done iterating (so that our while loop does not abort early)
        $sourceRedis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        // we have approx 80 000 entries (as of Mar 2021) in the "data" part
        // and it takes between 1 and 10; or at max 40 seconds to import them
        //
        // if we say a chunk should complete in 0.1s, we need < 400 chunks (at worst case for 40 seconds)
        // 80 000 records, divided by 400 chunks = 200 items per chunk.
        $startTime = microtime(true);
        $it = NULL;
        $numberOfBatches = 0;
        while ($arr_keys = $sourceRedis->hScan($keyToTransfer, $it, null, 200)) {
            $numberOfBatches++;
            $targetPipeline = $targetRedis->pipeline(); // we don't care for the replies or for transactionality; so we use pipelining instead of MULTI
            foreach ($arr_keys as $hashKey => $hashValue) {
                $targetPipeline->hSet($keyToTransfer, $hashKey, $hashValue);
            }
            $targetPipeline->exec();
        }
        $endTime = microtime(true);

        $actualNumberOfHashItems = $targetRedis->hLen($keyToTransfer);

        if ($expectedNumberOfHashItems !== $actualNumberOfHashItems) {
            $contentReleaseLogger->error('SYNC: (INCREMENTAL) !!!! Number of hash items mismatch for key ' . $keyToTransfer . ' - expected ' . $expectedNumberOfHashItems . ', actual: ' . $actualNumberOfHashItems);
            throw new \RuntimeException('!!!! Number of hash items mismatch for key ' . $keyToTransfer . ' - expected ' . $expectedNumberOfHashItems . ', actual: ' . $actualNumberOfHashItems);
        }

        $contentReleaseLogger->info(sprintf(
            'SYNC: (INCREMENTAL) Transferred key %s (count: %d, batches: %d, total time (pipelined, non-blocking): %2.3f)',
            $keyToTransfer,
            $actualNumberOfHashItems,
            $numberOfBatches,
            $endTime - $startTime,
        ));
    }
}
