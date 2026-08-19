<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\RedisPruneService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

class ContentStorePruneCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var RedisPruneService
     */
    protected $redisPruneService;

    public function pruneRedisInstanceCommand(string $redisInstanceIdentifier)
    {
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisInstanceIdentifier);

        $this->redisPruneService->pruneRedisInstance($redisInstanceIdentifier);
    }
}
