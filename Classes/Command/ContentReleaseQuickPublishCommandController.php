<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\DecoupledContentStore\QuickPublish\Infrastructure\RedisReleaseCopyService;
use Flowpack\DecoupledContentStore\QuickPublish\QuickPublishNodeEnumerator;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the QUICK PUBLISH stage in the pipeline. Not meant to be called manually.
 */
final class ContentReleaseQuickPublishCommandController extends CommandController
{
    #[Flow\Inject]
    protected RedisReleaseCopyService $redisReleaseCopyService;

    #[Flow\Inject]
    protected QuickPublishNodeEnumerator $quickPublishNodeEnumerator;

    /**
     * Take the content of a finished content release over into a new one, within the same redis instance.
     *
     * @param string $redisContentStoreIdentifier
     * @param string $sourceContentReleaseIdentifier the release to build upon - the one which is currently live
     * @param string $targetContentReleaseIdentifier the release being built
     */
    public function copyReleaseWithinCommand(
        string $redisContentStoreIdentifier,
        string $sourceContentReleaseIdentifier,
        string $targetContentReleaseIdentifier,
    ): void {
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisContentStoreIdentifier);
        $sourceIdentifier = ContentReleaseIdentifier::fromString($sourceContentReleaseIdentifier);
        $targetIdentifier = ContentReleaseIdentifier::fromString($targetContentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $targetIdentifier);

        try {
            $this->redisReleaseCopyService->copyReleaseWithin(
                $redisInstanceIdentifier,
                $sourceIdentifier,
                $targetIdentifier,
                $logger,
            );
        } catch (Exception $exception) {
            // the pipeline shows the task log, so an uncaught exception would bury the reason under a stack trace
            $logger->error($exception->getMessage());
            $this->quit(1);
        }
    }

    /**
     * Enumerate the given document nodes for rendering. Everything else in the release comes from the release it
     * was copied from.
     *
     * @param string $contentReleaseIdentifier the release being built
     * @param string $nodeIdentifiers the node identifiers to publish, separated by commas
     */
    public function enumerateGivenNodesCommand(string $contentReleaseIdentifier, string $nodeIdentifiers): void
    {
        $releaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $releaseIdentifier);

        try {
            $this->quickPublishNodeEnumerator->enumerateGivenNodesAndStoreInRedis(
                NodeIdentifiers::fromCommaSeparatedString($nodeIdentifiers),
                $logger,
                $releaseIdentifier,
            );
        } catch (Exception $exception) {
            $logger->error($exception->getMessage());
            $this->quit(1);
        }
    }
}
