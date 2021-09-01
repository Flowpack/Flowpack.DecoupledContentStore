<?php

namespace Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\Utility\GeneratorUtility;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;

/**
 * @Flow\Scope("singleton")
 */
class RedisContentReleaseService
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

    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    public function createContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, PrunnerJobId $prunnerJobId, ContentReleaseLogger $contentReleaseLogger)
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        $metadata = ContentReleaseMetadata::create($prunnerJobId, new \DateTimeImmutable());
        $redis->multi();
        try {
            $redis->zAdd('contentStore:registeredReleases', 0, $contentReleaseIdentifier->getIdentifier());
            $redis->set($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'meta:info'), json_encode($metadata));
            $redis->exec();
        } catch (\Exception $e) {
            $redis->discard();
            throw $e;
        }
        $contentReleaseLogger->info(sprintf('Registered Content Release %s', $contentReleaseIdentifier->getIdentifier()), [
            'metadata' => $metadata
        ]);
    }

    public function setContentReleaseMetadata(ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseMetadata $metadata, RedisInstanceIdentifier $redisInstanceIdentifier)
    {
        $this->redisClientManager->getRedis($redisInstanceIdentifier)->set($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'meta:info'), json_encode($metadata));
    }

    public function registerManualTransferJob(ContentReleaseIdentifier $contentReleaseIdentifier, PrunnerJobId $prunnerJobId, ContentReleaseLogger $contentReleaseLogger)
    {
        $releaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        $this->redisContentReleaseService->setContentReleaseMetadata($contentReleaseIdentifier, $releaseMetadata->withAdditionalManualTranferJobId($prunnerJobId), RedisInstanceIdentifier::primary());

        $contentReleaseLogger->info(sprintf('Register new pipeline for release %s', $contentReleaseIdentifier->getIdentifier()));
    }

    /**
     * @return ContentReleaseIdentifier[]
     * @throws \Exception
     */
    public function fetchAllReleaseIds(RedisInstanceIdentifier $redisInstanceIdentifier): array
    {
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $contentReleaseIds = $redis->zRevRangeByLex('contentStore:registeredReleases', '+', '-');

        $result = [];
        foreach ($contentReleaseIds as $contentReleaseId) {
            $result[] = ContentReleaseIdentifier::fromString($contentReleaseId);
        }
        return $result;
    }

    public function fetchMetadataForContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, ?RedisInstanceIdentifier $redisInstanceIdentifier = null): ContentReleaseMetadata
    {
        $redisInstanceIdentifier = $redisInstanceIdentifier ?: RedisInstanceIdentifier::primary();
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $metadataEncoded = $redis->get($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'meta:info'));
        return ContentReleaseMetadata::fromJsonString($metadataEncoded);
    }

    public function fetchMetadataForContentReleases(RedisInstanceIdentifier $redisInstanceIdentifier, ContentReleaseIdentifier ...$releaseIdentifiers): ContentReleaseBatchResult
    {
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $result = []; // KEY == contentReleaseIdentifier. VALUE == enumerated count
        foreach (GeneratorUtility::createArrayBatch($releaseIdentifiers, 50) as $batchedReleaseIdentifiers) {
            $redisPipeline = $redis->pipeline();
            foreach ($batchedReleaseIdentifiers as $releaseIdentifier) {
                $redisPipeline->get($this->redisKeyService->getRedisKeyForPostfix($releaseIdentifier, 'meta:info'));
            }
            $res = $redisPipeline->exec();
            foreach ($batchedReleaseIdentifiers as $i => $releaseIdentifier) {
                $result[$releaseIdentifier->jsonSerialize()] = ContentReleaseMetadata::fromJsonString($res[$i]);
            }
        }
        return ContentReleaseBatchResult::createFromArray($result);
    }

}
