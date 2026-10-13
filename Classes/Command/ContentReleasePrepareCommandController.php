<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Annotations as Flow;
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

    public function createContentReleaseCommand(
        string $contentReleaseIdentifier,
        string $prunnerJobId,
        string $workspaceName = 'live',
        string $accountId = 'cli',
    ): void {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->redisContentReleaseService->createContentRelease(
            $contentReleaseIdentifier,
            $prunnerJobId,
            $logger,
            $workspaceName,
            $accountId,
        );
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

    /**
     * Mark a release as failed from the pipeline's on_error hook
     *
     * Rendering sets the status to "success" before validation, transfer and switch have run, and only rendering
     * failures set "failed" - so without this, a release which fails later keeps reading "success" once prunner has
     * dropped its job. Pipelines which merely act on an existing release (e.g. a manual transfer) share that hook, so
     * only the job which built the release may mark it.
     */
    public function markFailedCommand(string $contentReleaseIdentifier, string $prunnerJobId): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $metadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        if ($metadata === null || $metadata->getPrunnerJobId()->getIdentifier() !== $prunnerJobId) {
            return;
        }

        $this->redisContentReleaseService->setContentReleaseMetadata(
            $contentReleaseIdentifier,
            $metadata->withStatus(NodeRenderingCompletionStatus::failed()),
            RedisInstanceIdentifier::primary(),
        );
    }

    public function flushContentCacheIfRequiredCommand(
        string $contentReleaseIdentifier,
        bool $flushContentCache = false,
    ): void {
        $logger = ContentReleaseLogger::fromConsoleOutput(
            $this->output,
            ContentReleaseIdentifier::fromString($contentReleaseIdentifier),
        );
        if (!$flushContentCache) {
            $logger->info('Not flushing content cache');
            return;
        }
        $logger->info('Flushing content cache');
        $this->contentCache->flush();
    }
}
