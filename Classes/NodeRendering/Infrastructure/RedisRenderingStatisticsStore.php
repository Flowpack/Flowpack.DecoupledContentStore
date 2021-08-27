<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;

/**
 * @Flow\Scope("singleton")
 */
class RedisRenderingStatisticsStore
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

    public function addStatisticsIteration(ContentReleaseIdentifier $contentReleaseIdentifier, ?RenderingStatistics $renderingStatistics)
    {
        $this->redisClientManager->getPrimaryRedis()->rPush($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingStatistics'), json_encode($renderingStatistics));
    }

    public function replaceLastStatisticsIteration(ContentReleaseIdentifier $contentReleaseIdentifier, RenderingStatistics $renderingStatistics)
    {
        $this->redisClientManager->getPrimaryRedis()->rPop($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingStatistics'));
        $this->addStatisticsIteration($contentReleaseIdentifier, $renderingStatistics);
    }

    public function getRenderingStatistics(ContentReleaseIdentifier $contentReleaseIdentifier): array
    {
        return $this->redisClientManager->getPrimaryRedis()->lRange($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingStatistics'), 0, -1);
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        $this->redisClientManager->getPrimaryRedis()->del($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'renderingStatistics'));
    }

    public function countMultipleRenderingStatistics(ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == count of statistics entries (= count of iterations)
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redis = $this->redisClientManager->getPrimaryRedis();
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->llen($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'renderingStatistics'));
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }

    public function getLastRenderingStatisticsEntry(ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == last rendering statistics entry)
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redis = $this->redisClientManager->getPrimaryRedis();
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->lindex($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'renderingStatistics'), -1);
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }

    public function getFirstRenderingStatisticsEntry(ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == first rendering statistics entry)
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redis = $this->redisClientManager->getPrimaryRedis();
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->lindex($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'renderingStatistics'), 0);
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }
}
