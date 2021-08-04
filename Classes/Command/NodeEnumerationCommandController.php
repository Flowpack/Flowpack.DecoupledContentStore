<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\NodeEnumerator;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the ENUMERATE stage in the pipeline. Not meant to be called manually.
 */
class NodeEnumerationCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeEnumerator
     */
    protected $nodeEnumerator;

    public function enumerateAllNodesCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        // TODO: is the NodeEnumerator called anywhere WITH a site? (in the old version)
        $this->nodeEnumerator->enumerateAndStoreInRedis(null, $logger, $contentReleaseIdentifier);
    }
}