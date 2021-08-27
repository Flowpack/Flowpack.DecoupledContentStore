<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents;

use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntimeEventInterface;
use Flowpack\DecoupledContentStore\NodeRendering\NodeRenderer;
use Neos\Flow\Annotations as Flow;

/**
 * Notification that a document was rendered. Emitted from {@see NodeRenderer}
 *
 * @Flow\Proxy(false)
 */
final class DocumentRenderedEvent implements InterruptibleProcessRuntimeEventInterface
{
    public static function create(): self
    {
        return new self();
    }
}
