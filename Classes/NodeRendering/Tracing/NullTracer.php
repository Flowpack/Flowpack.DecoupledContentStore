<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Tracing;

/**
 * Used when no performanceTracer is configured, so that the rendering can call the tracer unconditionally.
 */
final class NullTracer implements RenderTracerInterface
{
    public function openSpan(string $name, array $params = []): void
    {
    }

    public function closeSpan(): void
    {
    }

    public function mark(string $name, array $params = []): void
    {
    }

    public function describeRun(array $meta): void
    {
    }
}
