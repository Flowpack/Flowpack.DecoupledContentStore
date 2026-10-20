<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RendererIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntime;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the RENDERING stage in the pipeline. Not meant to be called manually.
 */
final class NodeRenderingCommandController extends CommandController
{
    public function __construct(
        private readonly NodeRenderOrchestrator $nodeRenderOrchestrator,
        private readonly NodeRenderer $nodeRenderer,
    ) {
        parent::__construct();
    }

    public function orchestrateRenderingCommand(string $contentReleaseIdentifier): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        InterruptibleProcessRuntime::create($this->nodeRenderOrchestrator->renderContentRelease(
            $contentReleaseIdentifier,
            $logger,
        ))->runUntilEnd();
    }

    public function renderWorkerCommand(string $contentReleaseIdentifier, string $rendererIdentifier): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $rendererIdentifier = RendererIdentifier::fromString($rendererIdentifier);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);

        InterruptibleProcessRuntime::create($this->nodeRenderer->render(
            $contentReleaseIdentifier,
            $logger,
            $rendererIdentifier,
        ))->runUntilEnd();
    }
}
