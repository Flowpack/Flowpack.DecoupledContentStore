<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

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

    public function createContentReleaseCommand(string $contentReleaseIdentifier, string $prunnerJobId)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $this->redisContentReleaseService->createContentRelease($contentReleaseIdentifier, $prunnerJobId, $logger);
    }

    public function registerManualTransferJobCommand(string $contentReleaseIdentifier, string $prunnerJobId)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $this->redisContentReleaseService->registerManualTransferJob($contentReleaseIdentifier, $prunnerJobId, $logger);
    }
}
