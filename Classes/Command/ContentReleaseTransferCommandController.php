<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Transfer\ContentReleaseCleaner;
use Flowpack\DecoupledContentStore\Transfer\ContentReleaseSynchronizer;
use Flowpack\DecoupledContentStore\Transfer\Resource\RemoteResourceSynchronizer;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the TRANSFER stage in the pipeline. Not meant to be called manually.
 */
class ContentReleaseTransferCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var RemoteResourceSynchronizer
     */
    protected $remoteResourceSynchronizer;

    /**
     * @Flow\Inject
     * @var ContentReleaseSynchronizer
     */
    protected $contentReleaseSynchronizer;

    /**
     * @Flow\Inject
     * @var ContentReleaseCleaner
     */
    protected $contentReleaseCleaner;

    public function syncResourcesCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->remoteResourceSynchronizer->synchronize($logger);
    }

    public function transferToContentStoreCommand(string $redisContentStoreIdentifier, string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisContentStoreIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->contentReleaseSynchronizer->syncToTarget($redisInstanceIdentifier, $contentReleaseIdentifier, $logger);
    }

    public function removeOldReleasesCommand(string $redisContentStoreIdentifier, string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisInstanceIdentifier = RedisInstanceIdentifier::fromString($redisContentStoreIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->contentReleaseCleaner->removeOldReleases($redisInstanceIdentifier, $contentReleaseIdentifier, $logger);
    }
}
