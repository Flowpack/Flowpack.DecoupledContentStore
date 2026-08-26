<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Tracing;

use Sandstorm\Plumber\Core\Domain\Model\EmptyProfilingRun;

/**
 * Records the rendering spans into the Plumber profiling run of the current render worker, so they show up in
 * /plumber next to the Fusion and database timers of the very same process.
 *
 * Spans are written when they close, not when they open: their duration is only known then, and
 * $minimumDurationMs drops the fast ones. The consequence is that spans end up as siblings of each other rather
 * than nested - ProfilingRun::manualTimer() appends a start and a stop event in one go and cannot express a
 * parent. The nesting is still recoverable from the timestamps, which is what the timeline exports use.
 *
 * @see RenderTracerInterface for the reason this lives outside Sandstorm.Plumber
 */
final class PlumberTracer implements RenderTracerInterface
{
    /**
     * Open spans, innermost last. Each entry is [name, params, startTimestamp].
     *
     * @var list<array{0: string, 1: array<string, mixed>, 2: float}>
     */
    private array $openSpans = [];

    public function __construct(
        private readonly EmptyProfilingRun $profilingRun,
        private readonly float $minimumDurationMs,
    ) {
    }

    public function openSpan(string $name, array $params = []): void
    {
        $this->openSpans[] = [$name, $params, microtime(true)];
    }

    public function closeSpan(): void
    {
        $span = array_pop($this->openSpans);
        if ($span === null) {
            throw new \RuntimeException('closeSpan() was called without a matching openSpan()', 1756200002);
        }

        [$name, $params, $startTimestamp] = $span;
        $stopTimestamp = microtime(true);
        if (($stopTimestamp - $startTimestamp) * 1000 < $this->minimumDurationMs) {
            return;
        }

        $this->profilingRun->manualTimer($name, $params, $startTimestamp, $stopTimestamp);
    }

    public function mark(string $name, array $params = []): void
    {
        $this->profilingRun->timestamp($name, $params);
    }

    public function describeRun(array $meta): void
    {
        foreach ($meta as $key => $value) {
            $this->profilingRun->setOption($key, $value);
        }

        $contentReleaseIdentifier = $meta[RenderTracerInterface::META_CONTENT_RELEASE] ?? null;
        if ($contentReleaseIdentifier === null) {
            return;
        }

        // The tag is what ties the profiles of all render workers of one release together; every worker restarts
        // after RESTART_AFTER_RENDER_COUNT documents and writes its own profile.
        $tag = 'contentRelease:' . $contentReleaseIdentifier;
        $tags = $this->profilingRun->getTags();
        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $this->profilingRun->setTags($tags);
        }
    }
}
