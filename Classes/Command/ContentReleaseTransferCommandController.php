<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Resource\RemoteResourceSynchronizer;
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

    public function syncResourcesCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->remoteResourceSynchronizer->synchronize($logger);
    }

    public function transferToContentStoreCommand(string $redisContentStoreIdentifier, string $contentReleaseIdentifier)
    {

    }
}