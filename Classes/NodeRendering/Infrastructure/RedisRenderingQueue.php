<?php

namespace Flowpack\DecoupledContentStore\NodeRendering\Infrastructure;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;

/**
 * @Flow\Scope("singleton")
 */
class RedisRenderingQueue
{

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    public function appendRenderingJob(ContentReleaseIdentifier $contentReleaseIdentifier, EnumeratedNode $enumeratedNode)
    {
        $encodedNode = json_encode($enumeratedNode);
        $this->redisClientManager->getPrimaryRedis()->rPush($contentReleaseIdentifier->redisKey('renderingJobQueue'), $encodedNode);
    }

    public function numberOfQueuedJobs(ContentReleaseIdentifier $contentReleaseIdentifier): int
    {
        return $this->redisClientManager->getPrimaryRedis()->lLen($contentReleaseIdentifier->redisKey('renderingJobQueue')) ?? 0;
    }

    public function numberOfRenderingsInProgress(ContentReleaseIdentifier $contentReleaseIdentifier): int
    {
        return $this->redisClientManager->getPrimaryRedis()->hLen($contentReleaseIdentifier->redisKey('inProgressRenderings')) ?? 0;
    }

    public function fetchAndReserveNextRenderingJob(ContentReleaseIdentifier $contentReleaseIdentifier, RendererIdentifier $rendererIdentifier): ?EnumeratedNode
    {
        $redis = $this->redisClientManager->getPrimaryRedis();

        // KEYS[1] is $renderingJobQueueKey
        // KEYS[2] is $renderingReservedJobsKey
        // ARGV[1] is $rendererIdentifier
        $script = "
            local renderingJobQueueKey = KEYS[1]
            local renderingReservedJobsKey = KEYS[2]
            local rendererIdentifier = ARGV[1]
        
            -- Return keys from table as the result
            local result = redis.call('LPOP', renderingJobQueueKey);
            if result then
                redis.call('HSET', renderingReservedJobsKey, result, rendererIdentifier)                
            end

            return result
        ";
        $nextEntry = $redis->eval($script, array($contentReleaseIdentifier->redisKey('renderingJobQueue'), $contentReleaseIdentifier->redisKey('inProgressRenderings'), $rendererIdentifier->string()), 2);
        if ($nextEntry === false && $redis->getLastError() !== null) {
            throw new \Exception('Redis operation EVAL failed: ' . $redis->getLastError(), 1471442667);
        }

        if ($nextEntry === false) {
            return null;
        }

        return EnumeratedNode::fromJsonString($nextEntry);
    }

    /**
     * @param ContentReleaseIdentifier $contentReleaseIdentifier
     * @param EnumeratedNode $enumeratedNode
     * @param RendererIdentifier $rendererIdentifier
     * @return bool TRUE if removal was successful or element was not found, FALSE if element was claimed by another renderer in the meantime (however this has happened)
     */
    public function removeRenderingJobFromReservedList(ContentReleaseIdentifier $contentReleaseIdentifier, EnumeratedNode $enumeratedNode, RendererIdentifier $rendererIdentifier): bool
    {
        // Defensive Programming: It might be that the job has been claimed by another worker in the meantime (no clue how this might have happened though)
        $script = "
            local renderingReservedJobsKey = KEYS[1]
            local enumeratedNode = ARGV[1]
            local rendererIdentifier = ARGV[2]
        
            -- Return keys from table as the result
            local reservedByRenderer = redis.call('HGET', renderingReservedJobsKey, enumeratedNode);
            if reservedByRenderer then
                if reservedByRenderer == rendererIdentifier then
                    redis.call('HDEL', renderingReservedJobsKey, enumeratedNode)                
                end
    
                return reservedByRenderer == rendererIdentifier
            else
                return true
            end
            
        ";
        $removalSuccessful = $this->redisClientManager->getPrimaryRedis()->eval($script, array($contentReleaseIdentifier->redisKey('inProgressRenderings'), json_encode($enumeratedNode), $rendererIdentifier->string()), 1);
        return $removalSuccessful;
    }

    public function flush(ContentReleaseIdentifier $contentReleaseIdentifier)
    {
        $this->redisClientManager->getPrimaryRedis()->del($contentReleaseIdentifier->redisKey('renderingJobQueue'), $contentReleaseIdentifier->redisKey('inProgressRenderings'));
    }

    public function setCompletionStatus(ContentReleaseIdentifier $contentReleaseIdentifier, \Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus $completionStatus): void
    {
        $this->redisClientManager->getPrimaryRedis()->set($contentReleaseIdentifier->redisKey('completionStatus'), json_encode($completionStatus));
    }

    public function getCompletionStatus(ContentReleaseIdentifier $contentReleaseIdentifier): ?NodeRenderingCompletionStatus
    {
        $completionStatus = $this->redisClientManager->getPrimaryRedis()->get($contentReleaseIdentifier->redisKey('completionStatus'));
        if ($completionStatus === false) {
            return null;
        }
        return NodeRenderingCompletionStatus::fromJsonString($completionStatus);
    }

    public function addRenderedUrl(ContentReleaseIdentifier $contentReleaseIdentifier, string $renderedUrl)
    {
        $this->redisClientManager->getPrimaryRedis()->zAdd($contentReleaseIdentifier->redisKey('meta:urls'), 0, $renderedUrl);
    }
}
