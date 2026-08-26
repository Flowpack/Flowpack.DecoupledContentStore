<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Tracing;

/**
 * Builds the tracer configured at Flowpack.DecoupledContentStore.nodeRendering.performanceTracer.
 *
 * Neos 9 passes a ContentRepositoryId to its equivalent; there is no such thing here, so only the free-form
 * options from the settings are handed over.
 */
interface RenderTracerFactoryInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function build(array $options): RenderTracerInterface;
}
