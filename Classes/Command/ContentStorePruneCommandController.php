<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\RedisPruneService;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Annotations as Flow;


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
