<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents;

use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntimeEventInterface;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderOrchestrator;
use Neos\Flow\Annotations as Flow;

/**
 * Notification that the rendering queue is fully filled. Emitted from {@see NodeRenderOrchestrator}
 *
 * @Flow\Proxy(false)
 */
final class RenderingQueueFilledEvent implements InterruptibleProcessRuntimeEventInterface
{
    public static function create(): self
    {
        return new self();
    }
}