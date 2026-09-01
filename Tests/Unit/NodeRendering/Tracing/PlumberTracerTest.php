<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\NodeRendering\Tracing;

use Flowpack\DecoupledContentStore\NodeRendering\Tracing\NullTracer;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\PlumberTracer;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\PlumberTracerFactory;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\RenderTracerInterface;
use Flowpack\DecoupledContentStore\NodeRendering\Tracing\RenderTracerProvider;
use Neos\Flow\Tests\UnitTestCase;
use ReflectionProperty;
use Sandstorm\Plumber\Core\Domain\Model\ProfilingRun;
use Sandstorm\Plumber\Core\Profiler;

/**
 * Tests the tracer slot of the rendering against a real Plumber ProfilingRun, because what matters is the shape of
 * the data which ends up in the profile - a mock of the run would only assert that we call the methods we call.
 */
final class PlumberTracerTest extends UnitTestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryProfilePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(ProfilingRun::class)) {
            self::markTestSkipped('sandstorm/plumber is an optional dependency and is not installed.');
        }
    }

    /**
     * The factory starts a profiling run, and the Profiler is a singleton for the whole process - so a test
     * which builds a tracer would otherwise leave a run recording, which the shutdown function of
     * Sandstorm.Plumber writes to disk when the test suite ends.
     */
    protected function tearDown(): void
    {
        if (class_exists(Profiler::class)) {
            $instance = new ReflectionProperty(Profiler::class, 'instance');
            Profiler::getInstance()->stop();
            $instance->setValue(null, null);
        }

        foreach ($this->temporaryProfilePaths as $path) {
            $fileNames = glob($path . '/*');
            if (is_array($fileNames)) {
                array_map('unlink', $fileNames);
            }
            rmdir($path);
        }
        $this->temporaryProfilePaths = [];

        parent::tearDown();
    }

    private function temporaryProfilePath(): string
    {
        $path = sys_get_temp_dir() . '/plumber-tracer-test-' . bin2hex(random_bytes(6));
        mkdir($path);
        $this->temporaryProfilePaths[] = $path;

        return $path;
    }

    public function testNothingConfiguredMeansNoTracer(): void
    {
        self::assertInstanceOf(NullTracer::class, $this->buildProvider(null)->getTracer());
        self::assertInstanceOf(NullTracer::class, $this->buildProvider([])->getTracer());
    }

    public function testAConfiguredFactoryIsUsed(): void
    {
        $provider = $this->buildProvider([
            'factoryObjectName' => PlumberTracerFactory::class,
            'options' => ['minimumDocumentDurationMs' => 0],
        ]);

        self::assertInstanceOf(PlumberTracer::class, $provider->getTracer());
    }

    public function testTheFactoryStartsAProfilingRunSoThatOnlyTheRenderingIsProfiled(): void
    {
        $profiler = Profiler::getInstance();
        $profiler->stop();
        $profiler->setConfigurationProvider(static fn() => ['profilePath' => 'php://memory']);

        $tracer = (new PlumberTracerFactory())->build([]);
        $tracer->mark('rendering started');

        /** @var ProfilingRun|null $run */
        $run = $profiler->stop();
        self::assertInstanceOf(ProfilingRun::class, $run);
        self::assertSame(['rendering started'], array_column($run->getTimestamps(), 'name'));
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

    public function testABatchWithoutASurvivingSpanIsNotWrittenAtAll(): void
    {
        $profilePath = $this->temporaryProfilePath();

        $run = new ProfilingRun();
        $run->start();
        $run->discardUnlessMarkedRelevant();
        $tracer = new PlumberTracer($run, 50.0);
        $tracer->openSpan('fast');
        $tracer->closeSpan();
        $run->stop();
        $run->save(['profilePath' => $profilePath]);

        self::assertSame([], glob($profilePath . '/*.profile'));
    }

    public function testABatchWithASlowDocumentIsWritten(): void
    {
        $profilePath = $this->temporaryProfilePath();

        $run = new ProfilingRun();
        $run->start();
        $run->discardUnlessMarkedRelevant();
        $tracer = new PlumberTracer($run, 50.0);
        $tracer->openSpan('slow');
        usleep(60000);
        $tracer->closeSpan();
        $run->stop();
        $run->save(['profilePath' => $profilePath]);

        $profileFiles = glob($profilePath . '/*.profile');
        self::assertIsArray($profileFiles);
        self::assertCount(1, $profileFiles);
        $metaFiles = glob($profilePath . '/*.meta.json');
        self::assertIsArray($metaFiles);
        self::assertCount(1, $metaFiles);
    }

    /**
     * Without a threshold every batch is interesting, so the run must not be armed - otherwise a release
     * configured to record everything would write nothing.
     */
    public function testTheFactoryOnlyArmsTheDiscardWhenAThresholdIsConfigured(): void
    {
        $profilePath = $this->temporaryProfilePath();

        $profiler = Profiler::getInstance();
        $profiler->stop();
        $profiler->setConfigurationProvider(static fn(): array => ['profilePath' => $profilePath]);

        $tracer = (new PlumberTracerFactory())->build(['minimumDocumentDurationMs' => 0]);
        $tracer->openSpan('anything');
        $tracer->closeSpan();

        $profiler->stopAndSave();

        $profileFiles = glob($profilePath . '/*.profile');
        self::assertIsArray($profileFiles);
        self::assertCount(1, $profileFiles);
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

    /**
     * @param array{factoryObjectName: ?string, options: ?array{minimumDocumentDurationMs: int}}|null $configuration
     * @return RenderTracerProvider
     */
    private function buildProvider(?array $configuration): RenderTracerProvider
    {
        $provider = new RenderTracerProvider();
        $reflection = new ReflectionProperty(RenderTracerProvider::class, 'configuration');
        $reflection->setValue($provider, $configuration);

        return $provider;
    }

    /**
     * @return array<string, array{start: float, stop: float, name: string, data: mixed[]}>
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
