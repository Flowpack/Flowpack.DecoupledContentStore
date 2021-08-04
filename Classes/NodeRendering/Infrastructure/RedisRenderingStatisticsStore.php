<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingProgress;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;

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

    public function addDataPointForRenderingsPerSecond(ContentReleaseIdentifier $contentReleaseIdentifier, float $renderingsPerSecond)
    {
        $this->redisClientManager->getPrimaryRedis()->rPush($contentReleaseIdentifier->redisKey('renderingTimings'), $renderingsPerSecond);
    }

    public function getRenderingsPerSecondSamples(ContentReleaseIdentifier $contentReleaseIdentifier): array
    {
        return $this->redisClientManager->getPrimaryRedis()->lRange($contentReleaseIdentifier->redisKey('renderingTimings'), 0, -1);
    }

    public function updateRenderingProgress(ContentReleaseIdentifier $contentReleaseIdentifier, RenderingProgress $renderingProgress)
    {
        $this->redisClientManager->getPrimaryRedis()->set($contentReleaseIdentifier->redisKey('renderingProgress'), json_encode($renderingProgress));
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        $this->redisClientManager->getPrimaryRedis()->del($contentReleaseIdentifier->redisKey('renderingTimings'));
    }

    public function getRenderingProgress(ContentReleaseIdentifier $releaseIdentifier): RenderingProgress
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        return RenderingProgress::fromJsonString($redis->get($releaseIdentifier->redisKey('renderingProgress')));
    }

    public function getMultipleRenderingProgress(ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == RenderingProgress
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redis = $this->redisClientManager->getPrimaryRedis();
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->get($releaseIdentifier->redisKey('renderingProgress'));

            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = RenderingProgress::fromJsonString($res[$i]);
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }
}