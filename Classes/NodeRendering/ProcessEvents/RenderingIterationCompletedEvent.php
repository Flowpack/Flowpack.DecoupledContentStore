<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents;

use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Neos\Flow\Annotations as Flow;

/**
 * Notification that the rendering iteration was completed once. Emitted from {@see NodeRenderOrchestrator}
 *
 * @Flow\Proxy(false)
 */
final class RenderingIterationCompletedEvent
{
    public static function create(): self
    {
        return new self();
    }
}