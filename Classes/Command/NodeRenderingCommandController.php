<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the RENDERING stage in the pipeline. Not meant to be called manually.
 */
class NodeRenderingCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeRenderOrchestrator
     */
    protected $nodeRenderOrchestrator;

    /**
     * @Flow\Inject
     * @var NodeRenderer
     */
    protected $nodeRenderer;

    public function orchestrateRenderingCommand(string $contentReleaseIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $this->nodeRenderOrchestrator->renderContentRelease($contentReleaseIdentifier, $logger);
    }

    public function renderWorkerCommand(string $contentReleaseIdentifier, string $rendererIdentifier)
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $rendererIdentifier = RendererIdentifier::fromString($rendererIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        $this->nodeRenderer->render($contentReleaseIdentifier, $logger, $rendererIdentifier);
    }
}