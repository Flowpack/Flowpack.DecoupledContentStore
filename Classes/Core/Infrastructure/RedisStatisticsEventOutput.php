<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Neos\Flow\Annotations as Flow;

class RedisStatisticsEventOutput implements StatisticsEventOutputInterface
{

    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected RedisKeyService $redisKeyService;

    public function writeEvent(ContentReleaseIdentifier $contentReleaseIdentifier, string $prefix, string $event, array $additionalPayload): void
    {
        $this->redisClientManager->getPrimaryRedis()->rPush($this->redisKeyService->getRedisKeyForPostfix($contentReleaseIdentifier, 'statisticsEvents'), json_encode([
            'event' => $event,
            'prefix' => $prefix,
            'additionalPayload' => $additionalPayload,
        ]));
    }
}
