<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

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
    public function switchActiveContentReleaseCommand(string $redisContentStoreIdentifier, string $contentReleaseIdentifier)
    {

    }
}