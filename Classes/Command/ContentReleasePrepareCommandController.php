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
use Neos\Fusion\Core\Cache\ContentCache;

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

    /**
     * @Flow\Inject
     * @var ContentCache
     */
    protected $contentCache;

    public function createContentReleaseCommand(string $contentReleaseIdentifier, string $prunnerJobId, string $workspaceName = 'live'): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $this->redisContentReleaseService->createContentRelease($contentReleaseIdentifier, $prunnerJobId, $logger, $workspaceName);
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

    public function flushContentCacheIfRequiredCommand(string $contentReleaseIdentifier, bool $flushContentCache = false): void
    {
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, ContentReleaseIdentifier::fromString($contentReleaseIdentifier));
        if (!$flushContentCache) {
            $logger->info('Not flushing content cache');
            return;
        }
        $logger->info('Flushing content cache');
        $this->contentCache->flush();
    }
}
