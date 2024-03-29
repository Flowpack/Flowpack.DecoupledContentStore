<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the PREPARE stage in the pipeline. Not meant to be called manually.
 */
class ContentReleasePrepareCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    /**
     * @Flow\Inject
     * @var ConcurrentBuildLockService
     */
    protected $concurrentBuildLock;

    public function createContentReleaseCommand(string $contentReleaseIdentifier, string $prunnerJobId, string $workspaceName = 'live', string $accountId = 'cli'): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->redisContentReleaseService->createContentRelease($contentReleaseIdentifier, $prunnerJobId, $logger, $workspaceName, $accountId);
    }

    public function ensureAllOtherInProgressContentReleasesWillBeTerminatedCommand(string $contentReleaseIdentifier): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);

        $this->concurrentBuildLock->ensureAllOtherInProgressContentReleasesWillBeTerminated($contentReleaseIdentifier);
    }

    public function registerManualTransferJobCommand(string $contentReleaseIdentifier, string $prunnerJobId): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $this->redisContentReleaseService->registerManualTransferJob($contentReleaseIdentifier, $prunnerJobId, $logger);
    }
}
