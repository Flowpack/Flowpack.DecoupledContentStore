<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents;

use Flowpack\DecoupledContentStore\NodeRendering\InterruptibleProcessRuntimeEventInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class QueueEmptyEvent implements InterruptibleProcessRuntimeEventInterface
{
    public static function create(): self
    {
        return new self();
    }
}