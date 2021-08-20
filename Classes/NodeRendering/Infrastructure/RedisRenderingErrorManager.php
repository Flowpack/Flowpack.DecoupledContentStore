<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
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

    public function registerRenderingError(ContentReleaseIdentifier $contentReleaseIdentifier, array $additionalData, \Exception $exception): void
    {
        $this->redisClientManager->getPrimaryRedis()->sAdd($contentReleaseIdentifier->redisKey('renderingErrors'), $exception->getMessage() . ' - ' . json_encode($additionalData));
    }


    public function getRenderingErrors(ContentReleaseIdentifier $contentReleaseIdentifier): array
    {
        return $this->redisClientManager->getPrimaryRedis()->sMembers($contentReleaseIdentifier->redisKey('renderingErrors'));
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier): void
    {
        $this->redisClientManager->getPrimaryRedis()->del($contentReleaseIdentifier->redisKey('renderingErrors'));
    }

    public function countMultipleErrors(ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == RenderingStatistics
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redis = $this->redisClientManager->getPrimaryRedis();
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->scard($releaseIdentifier->redisKey('renderingErrors'));
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }

}
