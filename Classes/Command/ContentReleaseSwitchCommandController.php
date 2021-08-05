<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Flowpack\DecoupledContentStore\Resource\RemoteResourceSynchronizer;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the SWITCH stage in the pipeline. Not meant to be called manually.
 */
class ContentReleaseSwitchCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;

    public function switchActiveContentReleaseCommand(string $redisInstanceIdentifier, string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisInstanceIdentifier);
        $this->redisReleaseSwitchService->switchContentRelease($redisInstanceIdentifier, $contentReleaseIdentifier, $logger);
    }
}