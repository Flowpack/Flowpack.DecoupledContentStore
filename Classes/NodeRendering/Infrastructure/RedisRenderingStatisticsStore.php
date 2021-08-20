<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
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

    public function addStatisticsIteration(ContentReleaseIdentifier $contentReleaseIdentifier, ?RenderingStatistics $renderingStatistics)
    {
        $this->redisClientManager->getPrimaryRedis()->rPush($contentReleaseIdentifier->redisKey('renderingStatistics'), json_encode($renderingStatistics));
    }

    public function replaceLastStatisticsIteration(ContentReleaseIdentifier $contentReleaseIdentifier, RenderingStatistics $renderingStatistics)
    {
        $this->redisClientManager->getPrimaryRedis()->rPop($contentReleaseIdentifier->redisKey('renderingStatistics'));
        $this->addStatisticsIteration($contentReleaseIdentifier, $renderingStatistics);
    }

    public function getRenderingStatistics(ContentReleaseIdentifier $contentReleaseIdentifier): array
    {
        return $this->redisClientManager->getPrimaryRedis()->lRange($contentReleaseIdentifier->redisKey('renderingStatistics'), 0, -1);
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        $this->redisClientManager->getPrimaryRedis()->del($contentReleaseIdentifier->redisKey('renderingStatistics'));
    }

    public function countMultipleRenderingStatistics(ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == RenderingStatistics
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redis = $this->redisClientManager->getPrimaryRedis();
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->llen($releaseIdentifier->redisKey('renderingStatistics'));
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }
}
