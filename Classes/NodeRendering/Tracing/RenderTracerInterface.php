<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Tracing;

/**
 * Collects timing information about the rendering of a content release.
 *
 * The three span methods mirror Neos 9's
 * \Neos\ContentRepository\Core\Infrastructure\PerformanceTracing\PerformanceTracerInterface method for method, so
 * that a Neos 9 upgrade can drop this interface and use theirs. {@see describeRun()} is the one addition: a render
 * worker is a whole process with a content release and a renderer id attached to it, and that metadata belongs to
 * no single span.
 *
 * Implementations are instantiated through {@see RenderTracerFactoryInterface} and configured at
 * Flowpack.DecoupledContentStore.nodeRendering.performanceTracer. When nothing is configured, {@see NullTracer}
 * is used, so call sites never need a null check.
 */
interface RenderTracerInterface
{
    /**
     * Key of the content release identifier within the {@see describeRun()} metadata. Tracers which turn the
     * metadata into a display label can use it as it is.
     */
    public const META_CONTENT_RELEASE = 'Content Release';

    /**
     * Key of the renderer id within the {@see describeRun()} metadata.
     */
    public const META_RENDERER = 'Renderer';

    /**
     * @param array<string, mixed> $params
     */
    public function openSpan(string $name, array $params = []): void;

    public function closeSpan(): void;

    /**
     * @param array<string, mixed> $params
     */
    public function mark(string $name, array $params = []): void;

    /**
     * Metadata describing the whole worker process, e.g. the content release it renders for.
     *
     * @param array<string, string> $meta
     */
    public function describeRun(array $meta): void;
}
