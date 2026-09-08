<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Metrics\Dto;

use Flowpack\DecoupledContentStore\BackendUi\Dto\WorkerErrorLog;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Metrics\Dto\ContentReleaseMetrics;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Tests the measurement which is shipped for every content release. It is the only trace a release leaves once
 * Redis has pruned it, so the numbers have to be right even for a release which never finished.
 */
final class ContentReleaseMetricsTest extends UnitTestCase
{
    private const RELEASE_ID = '20260904120000';
    private const NODE_IDENTIFIER = 'de3b7ec6-b9d1-40d1-91ae-a9c1a4d47dab';

    public function testIterationsAreCountedAndNumberedInOrder(): void
    {
        $metrics = $this->createMetrics(
            [
                RenderingStatistics::create(0, 120, []),
                RenderingStatistics::create(0, 8, []),
                RenderingStatistics::create(0, 0, []),
            ],
            [],
        );

        self::assertSame(3, $metrics->iterationCount);
        self::assertSame(
            [
                ['iteration' => 1, 'scheduledRenderings' => 120, 'completedRenderings' => 120],
                ['iteration' => 2, 'scheduledRenderings' => 8, 'completedRenderings' => 8],
                ['iteration' => 3, 'scheduledRenderings' => 0, 'completedRenderings' => 0],
            ],
            array_map(fn($iteration) => $iteration->jsonSerialize(), $metrics->iterations),
        );
        self::assertSame(128, $metrics->renderingCount);
    }

    public function testNewlyRenderedDocumentsAreTheOnesTheFirstIterationScheduled(): void
    {
        $metrics = $this->createMetrics(
            [RenderingStatistics::create(0, 12, []), RenderingStatistics::create(0, 0, [])],
            [],
        );

        self::assertSame(500, $metrics->documentCount);
        self::assertSame(12, $metrics->newlyRenderedDocumentCount);
    }

    public function testAReleaseWithoutAnyIterationReportsZeroRenderings(): void
    {
        $metrics = $this->createMetrics([], []);

        self::assertSame(0, $metrics->iterationCount);
        self::assertSame(0, $metrics->newlyRenderedDocumentCount);
        self::assertSame(0, $metrics->renderingCount);
    }

    public function testDurationOfAFinishedReleaseIsMeasuredBetweenStartAndEndTime(): void
    {
        $metadata = ContentReleaseMetadata::create(
            PrunnerJobId::fromString('job-1'),
            new \DateTimeImmutable('2026-09-04T12:00:00+00:00'),
        )->withEndTime(new \DateTimeImmutable('2026-09-04T12:03:20+00:00'));

        $metrics = ContentReleaseMetrics::create(
            ContentReleaseIdentifier::fromString(self::RELEASE_ID),
            $metadata,
            500,
            [],
            [],
            [],
            new \DateTimeImmutable('2026-09-04T13:00:00+00:00'),
        );

        self::assertSame(200, $metrics->durationSeconds);
        self::assertStringStartsWith('2026-09-04T12:03:20', (string) $metrics->endTime);
    }

    public function testDurationOfAnAbortedReleaseIsMeasuredUpToTheReportingTime(): void
    {
        // an aborted release never gets an end time written
        $metrics = $this->createMetrics([], [], new \DateTimeImmutable('2026-09-04T12:01:00+00:00'));

        self::assertNull($metrics->endTime);
        self::assertSame(60, $metrics->durationSeconds);
    }

    public function testRenderingErrorIsSplitIntoMessageAndNode(): void
    {
        $rawEntry = 'Exception while rendering - '
            . json_encode([
                'node' => 'Louis.Site:Document.Page ' . self::NODE_IDENTIFIER . ' (/sites/louis@live;language=de) - htmlViaFusion',
                'nodeUri' => 'https://de.louis.de/foo',
            ]);

        $metrics = $this->createMetrics([], [$rawEntry]);

        self::assertSame(1, $metrics->renderingErrorCount);
        self::assertSame('Exception while rendering', $metrics->renderingErrors[0]->message);
        self::assertSame(self::NODE_IDENTIFIER, $metrics->renderingErrors[0]->nodeIdentifier);
        self::assertSame('https://de.louis.de/foo', $metrics->renderingErrors[0]->nodeUri);
    }

    public function testRenderingErrorWithoutAdditionalDataKeepsItsMessage(): void
    {
        $metrics = $this->createMetrics([], ['Invalid release due to low URL count - []']);

        self::assertSame('Invalid release due to low URL count', $metrics->renderingErrors[0]->message);
        self::assertNull($metrics->renderingErrors[0]->nodeIdentifier);
    }

    public function testAnUnparseableErrorEntryIsReportedAsIs(): void
    {
        $metrics = $this->createMetrics([], ['something nobody formatted']);

        self::assertSame('something nobody formatted', $metrics->renderingErrors[0]->message);
        self::assertNull($metrics->renderingErrors[0]->node);
    }

    public function testErrorListIsTruncatedButTheCountIsNot(): void
    {
        $rawEntries = array_map(fn(int $i) => 'Error ' . $i . ' - []', range(1, 120));

        $metrics = $this->createMetrics([], $rawEntries);

        self::assertSame(120, $metrics->renderingErrorCount);
        self::assertCount(50, $metrics->renderingErrors);
    }

    public function testWorkerErrorCarriesTheNodeTheWorkerDiedOn(): void
    {
        $workerErrorLog = new WorkerErrorLog(
            'render_3',
            'error',
            255,
            null,
            ["PHP Fatal error: Allowed memory size exhausted\n#0 /app/Foo.php(1)"],
            'Louis.Site:Document.Page ' . self::NODE_IDENTIFIER . ' (/sites/louis@live) | https://de.louis.de/foo',
        );

        $metrics = $this->createMetrics([], [], null, [$workerErrorLog]);

        self::assertCount(1, $metrics->workerErrors);
        self::assertSame('render_3', $metrics->workerErrors[0]->workerName);
        self::assertFalse($metrics->workerErrors[0]->wasKilledByOrchestrator);
        self::assertSame(self::NODE_IDENTIFIER, $metrics->workerErrors[0]->lastAttemptedNodeIdentifier);
        self::assertStringContainsString('memory size exhausted', $metrics->workerErrors[0]->errorBlocks[0]);
    }

    public function testWorkerKilledByTheOrchestratorIsMarkedAsSuch(): void
    {
        // exit code 143 = 128 + SIGTERM: the orchestrator stopped this worker after another one failed
        $workerErrorLog = new WorkerErrorLog('render_7', 'error', 143, null, [], null);

        $metrics = $this->createMetrics([], [], null, [$workerErrorLog]);

        self::assertTrue($metrics->workerErrors[0]->wasKilledByOrchestrator);
        self::assertNull($metrics->workerErrors[0]->lastAttemptedNodeIdentifier);
    }

    public function testUnreadableWorkerLogsAreReportedAsUnknownRatherThanEmpty(): void
    {
        $metrics = $this->createMetrics([], [], null, null);

        self::assertNull($metrics->workerErrors);
    }

    public function testWorkerErrorListIsCappedAtTenEntries(): void
    {
        $workerErrorLogs = array_map(
            fn(int $i) => new WorkerErrorLog('render_' . $i, 'error', 143, null, [], null),
            range(1, 24),
        );

        $metrics = $this->createMetrics([], [], null, $workerErrorLogs);

        self::assertCount(10, $metrics->workerErrors);
    }

    /**
     * @param RenderingStatistics[] $renderingStatistics
     * @param string[] $rawRenderingErrors
     * @param WorkerErrorLog[]|null $workerErrorLogs
     */
    private function createMetrics(
        array $renderingStatistics,
        array $rawRenderingErrors,
        ?\DateTimeInterface $now = null,
        ?array $workerErrorLogs = [],
    ): ContentReleaseMetrics {
        return ContentReleaseMetrics::create(
            ContentReleaseIdentifier::fromString(self::RELEASE_ID),
            ContentReleaseMetadata::create(
                PrunnerJobId::fromString('job-1'),
                new \DateTimeImmutable('2026-09-04T12:00:00+00:00'),
            ),
            500,
            $renderingStatistics,
            $rawRenderingErrors,
            $workerErrorLogs,
            $now ?? new \DateTimeImmutable('2026-09-04T12:00:00+00:00'),
        );
    }
}
