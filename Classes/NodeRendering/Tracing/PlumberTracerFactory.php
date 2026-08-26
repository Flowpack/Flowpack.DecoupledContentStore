<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Tracing;

use Sandstorm\Plumber\Core\Profiler;

/**
 * Options:
 *   minimumDocumentDurationMs - documents faster than this are not recorded at all. 0 records everything.
 */
final class PlumberTracerFactory implements RenderTracerFactoryInterface
{
    public function build(array $options): RenderTracerInterface
    {
        if (!class_exists(Profiler::class)) {
            throw new \RuntimeException(
                'The configured performanceTracer needs sandstorm/plumber, which is not installed. Either run'
                . ' "composer require --dev sandstorm/plumber" or comment the performanceTracer setting out again.',
                1756200003,
            );
        }

        return new PlumberTracer(
            Profiler::getInstance()->getRun(),
            (float)($options['minimumDocumentDurationMs'] ?? 0),
        );
    }
}
