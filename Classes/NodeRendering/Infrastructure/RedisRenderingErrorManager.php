<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
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

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

    public function registerRenderingError(ContentReleaseIdentifier $contentReleaseIdentifier, array $additionalData, \Exception $exception): void
    {
        $this->redisClientManager->getPrimaryRedis()->sAdd($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingErrors'), $exception->getMessage() . ' - ' . json_encode($additionalData));
    }

    public function getRenderingErrors(ContentReleaseIdentifier $contentReleaseIdentifier, ?RedisInstanceIdentifier $redisInstanceIdentifier = null): array
    {
        $redisInstanceIdentifier = $redisInstanceIdentifier ?: RedisInstanceIdentifier::primary();
        return $this->redisClientManager->getRedis($redisInstanceIdentifier)->sMembers($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingErrors'));
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier): void
    {
        $this->redisClientManager->getPrimaryRedis()->del($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingErrors'));
    }

    public function countMultipleErrors(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == count of error entries
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->scard($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'renderingErrors'));
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }

}
