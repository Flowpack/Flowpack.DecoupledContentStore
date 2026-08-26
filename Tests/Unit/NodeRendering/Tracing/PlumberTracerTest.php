<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeRendering\Tracing;

use Flowpack\DecoupledContentStore\NodeRendering\Tracing\NullTracer;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\PlumberTracer;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\RenderTracerInterface;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\RenderTracerProvider;
use Neos\Flow\Tests\UnitTestCase;
use Sandstorm\Plumber\Core\Domain\Model\ProfilingRun;

/**
 * Tests the tracer slot of the rendering against a real Plumber ProfilingRun, because what matters is the shape of
 * the data which ends up in the profile - a mock of the run would only assert that we call the methods we call.
 */
final class PlumberTracerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(ProfilingRun::class)) {
            self::markTestSkipped('sandstorm/plumber is an optional dependency and is not installed.');
        }
    }

    public function testNothingConfiguredMeansNoTracer(): void
    {
        self::assertInstanceOf(NullTracer::class, $this->buildProvider(null)->getTracer());
        self::assertInstanceOf(NullTracer::class, $this->buildProvider([])->getTracer());
    }

    public function testAConfiguredFactoryIsUsed(): void
    {
        $provider = $this->buildProvider([
            'factoryObjectName' => \Flowpack\DecoupledContentStore\NodeRendering\Tracing\PlumberTracerFactory::class,
            'options' => ['minimumDocumentDurationMs' => 0],
        ]);

        self::assertInstanceOf(PlumberTracer::class, $provider->getTracer());
    }

    public function testAFactoryWhichDoesNotImplementTheInterfaceIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->buildProvider(['factoryObjectName' => \stdClass::class])->getTracer();
    }

    public function testSpansAreRecordedWithTheirNameAndParams(): void
    {
        $run = new ProfilingRun();
        $run->start();
        $tracer = new PlumberTracer($run, 0.0);

        $tracer->openSpan('Content Release: Render Document', ['site' => 'louis']);
        $tracer->openSpan('Content Release Document: /sites/louis@live', ['site' => 'louis']);
        $tracer->closeSpan();
        $tracer->closeSpan();

        $timers = $this->timersByName($run);
        self::assertArrayHasKey('Content Release: Render Document', $timers);
        self::assertArrayHasKey('Content Release Document: /sites/louis@live', $timers);
        self::assertSame(['site' => 'louis'], $timers['Content Release: Render Document']['data']);
    }

    public function testADocumentBelowTheThresholdIsNotRecorded(): void
    {
        $run = new ProfilingRun();
        $run->start();
        $tracer = new PlumberTracer($run, 50.0);

        $tracer->openSpan('fast');
        $tracer->closeSpan();

        $tracer->openSpan('slow');
        usleep(60000);
        $tracer->closeSpan();

        $timers = $this->timersByName($run);
        self::assertArrayNotHasKey('fast', $timers);
        self::assertArrayHasKey('slow', $timers);
    }

    public function testClosingMoreSpansThanWereOpenedIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PlumberTracer(new ProfilingRun(), 0.0))->closeSpan();
    }

    public function testTheRunIsTaggedWithTheContentReleaseItRendersFor(): void
    {
        $run = new ProfilingRun();
        $run->start();
        $tracer = new PlumberTracer($run, 0.0);

        $tracer->describeRun([
            RenderTracerInterface::META_CONTENT_RELEASE => '1756123456',
            RenderTracerInterface::META_RENDERER => 'htmlViaFusion',
        ]);

        self::assertSame(['contentRelease:1756123456'], $run->getTags());
        self::assertSame(
            ['Content Release' => '1756123456', 'Renderer' => 'htmlViaFusion'],
            $run->getOptions(),
        );
    }

    private function buildProvider(?array $configuration): RenderTracerProvider
    {
        $provider = new RenderTracerProvider();
        $reflection = new \ReflectionProperty(RenderTracerProvider::class, 'configuration');
        $reflection->setAccessible(true);
        $reflection->setValue($provider, $configuration);

        return $provider;
    }

    /**
     * @return array<string, array{start: float, stop: float, name: string, data: array}>
     */
    private function timersByName(ProfilingRun $run): array
    {
        $timers = [];
        foreach ($run->getTimersAsDuration() as $timer) {
            $timers[$timer['name']] = $timer;
        }
        unset($timers['Profiling Run']);

        return $timers;
    }
}
