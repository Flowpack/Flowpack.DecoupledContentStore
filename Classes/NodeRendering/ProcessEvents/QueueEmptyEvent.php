<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\ProcessEvents;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class QueueEmptyEvent
{
    public static function create(): self
    {
        return new self();
    }
}