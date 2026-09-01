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

        // startIfNotRunning() instead of getRun(): Sandstorm.Plumber is normally switched off, so that a
        // backend click does not end up in the profile list next to the content release. Starting the run
        // here means the process which renders documents is the only one which produces a profile at all.
        $profilingRun = Profiler::getInstance()->startIfNotRunning();

        $minimumDocumentDurationMs = (float)($options['minimumDocumentDurationMs'] ?? 0);
        if ($minimumDocumentDurationMs > 0) {
            // A render worker restarts every twenty documents and each restart writes a profile, so a full
            // release leaves ~1800 of them behind and /plumber cannot open that many. With a threshold set,
            // only the batches in which a document actually crossed it are kept.
            $profilingRun->discardUnlessMarkedRelevant();
        }

        return new PlumberTracer($profilingRun, $minimumDocumentDurationMs);
    }
}
