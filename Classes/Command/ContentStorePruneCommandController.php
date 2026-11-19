<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\RedisPruneService;
use Neos\Flow\Cli\CommandController;

final class ContentStorePruneCommandController extends CommandController
{
    public function __construct(
        private readonly RedisPruneService $redisPruneService,
    ) {
        parent::__construct();
    }

    public function pruneRedisInstanceCommand(string $redisInstanceIdentifier): void
    {
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisInstanceIdentifier);

        $this->redisPruneService->pruneRedisInstance($redisInstanceIdentifier);
    }
}
