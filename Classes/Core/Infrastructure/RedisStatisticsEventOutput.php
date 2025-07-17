<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Neos\Flow\Annotations as Flow;

class RedisStatisticsEventOutput implements StatisticsEventOutputInterface
{
    #[Flow\Inject]
    protected RedisStatisticsEventService $redisStatisticsEventService;

    public function writeEvent(ContentReleaseIdentifier $contentReleaseIdentifier, string $prefix, string $event, array $additionalPayload): void
    {
        $this->redisStatisticsEventService->addEvent($contentReleaseIdentifier, $prefix, $event, $additionalPayload);
    }
}
