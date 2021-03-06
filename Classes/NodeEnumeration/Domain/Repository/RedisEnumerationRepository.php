<?php

namespace Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository;

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
class RedisEnumerationRepository
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

    public function clearDocumentNodesEnumeration(ContentReleaseIdentifier $releaseIdentifier)
    {
        $this->redisClientManager->getPrimaryRedis()->del($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'enumeration:documentNodes'));
    }

    public function addDocumentNodesToEnumeration(ContentReleaseIdentifier $releaseIdentifier, EnumeratedNode ...$enumeration)
    {
        $convertedEnumeration = array_map(function (EnumeratedNode $node) {
            return json_encode($node);
        }, $enumeration);
        $this->redisClientManager->getPrimaryRedis()->rPush($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'enumeration:documentNodes'), ...$convertedEnumeration);
    }

    /**
     * @return iterable<EnumeratedNode>
     */
    public function findAll(ContentReleaseIdentifier $releaseIdentifier): iterable
    {
        foreach ($this->redisClientManager->getPrimaryRedis()->lRange($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'enumeration:documentNodes'), 0, -1) as $enumeratedNodeString) {
            yield EnumeratedNode::fromJsonString($enumeratedNodeString);
        }
    }

    public function count(ContentReleaseIdentifier $releaseIdentifier): int
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        $res = $redis->lLen($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'enumeration:documentNodes'));
        if (is_int($res)) {
            return $res;
        }
        return 0;
    }

    public function countMultiple(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $result = []; // KEY == contentReleaseIdentifier. VALUE == enumerated count
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->lLen($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'enumeration:documentNodes'));
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = $res[$i];
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }
}
