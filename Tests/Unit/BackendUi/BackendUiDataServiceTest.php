<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\BackendUiDataService;
use Flowpack\DecoupledContentStore\BackendUi\Dto\RemovedContentReleaseOverviewRow;
use Flowpack\DecoupledContentStore\Core\Domain\Dto\ContentReleaseBatchResult;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\Prunner\Dto\PipelinesAndJobsResponse;
use Flowpack\Prunner\PrunnerApiService;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Tests which prunner jobs the overview lists as removed releases.
 */
final class BackendUiDataServiceTest extends UnitTestCase
{
    public function testOnlyStartedReleaseJobsWithoutARegisteredReleaseAreListed(): void
    {
        $rows = $this->buildService([
            self::job('registered', 'do_content_release', '2026-10-01T10:00:00+00:00', '100'),
            self::job('removed-full', 'do_content_release', '2026-10-01T08:00:00+00:00', '80'),
            self::job('removed-quick', 'do_quick_content_release', '2026-10-01T09:00:00+00:00', '90'),
            // replaced while waiting, so it never ran and wrote no log
            self::job('replaced', 'do_content_release', null, '70'),
            // refers to a release which exists, but describes a transfer rather than a build
            self::job('transfer', 'manually_transfer_content_release', '2026-10-01T11:00:00+00:00', '80'),
        ])->loadRemovedReleasesOverviewData(RedisInstanceIdentifier::primary());
        self::assertNotNull($rows);

        $jobIds = array_map(
            static fn(RemovedContentReleaseOverviewRow $row): string => $row->getJob()->getId()->getId(),
            $rows,
        );
        self::assertSame(['removed-quick', 'removed-full'], $jobIds);
    }

    public function testTheKeptMetadataIsShownWhileItHasNotExpired(): void
    {
        $metadata = ContentReleaseMetadata::create(PrunnerJobId::fromString('removed'), new \DateTimeImmutable());

        $rows = $this->buildService(
            [self::job('removed', 'do_content_release', '2026-10-01T08:00:00+00:00', '80')],
            ['80' => $metadata],
        )->loadRemovedReleasesOverviewData(RedisInstanceIdentifier::primary());

        self::assertNotNull($rows);
        self::assertSame($metadata, $rows[0]->getMetadata());
    }

    public function testNothingIsListedForAContentStoreWhichDoesNotBuildReleases(): void
    {
        // a release which was never transferred to this content store has no registered release there either
        $rows = $this->buildService([self::job('removed', 'do_content_release', '2026-10-01T08:00:00+00:00', '80')])
            ->loadRemovedReleasesOverviewData(RedisInstanceIdentifier::fromString('target'));

        self::assertSame([], $rows);
    }

    public function testAnUnreachablePrunnerIsReportedInsteadOfBreakingTheOverview(): void
    {
        $prunnerApiService = $this->createMock(PrunnerApiService::class);
        $prunnerApiService->method('loadPipelinesAndJobs')->willThrowException(new \RuntimeException('down'));

        $service = $this->buildService([]);
        $this->inject($service, 'prunnerApiService', $prunnerApiService);

        self::assertNull($service->loadRemovedReleasesOverviewData(RedisInstanceIdentifier::primary()));
    }

    /**
     * @param array<array<string, mixed>> $jobs
     * @param array<array-key, ContentReleaseMetadata> $metadata
     */
    private function buildService(array $jobs, array $metadata = []): BackendUiDataService
    {
        $prunnerApiService = $this->createMock(PrunnerApiService::class);
        $prunnerApiService->method('loadPipelinesAndJobs')
            ->willReturn(
                PipelinesAndJobsResponse::fromJsonArray(['pipelines' => [], 'jobs' => $jobs]),
            );

        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $redisContentReleaseService->method('fetchAllReleaseIds')
            ->willReturn([
                ContentReleaseIdentifier::fromString('100'),
            ]);
        $redisContentReleaseService->method('fetchMetadataForContentReleases')
            ->willReturn(
                ContentReleaseBatchResult::createFromArray($metadata),
            );

        $redisRenderingErrorManager = $this->createMock(RedisRenderingErrorManager::class);
        $redisRenderingErrorManager->method('countMultipleErrors')
            ->willReturn(
                ContentReleaseBatchResult::createFromArray([]),
            );

        $service = new BackendUiDataService();
        $this->inject($service, 'prunnerApiService', $prunnerApiService);
        $this->inject($service, 'redisContentReleaseService', $redisContentReleaseService);
        $this->inject($service, 'redisRenderingErrorManager', $redisRenderingErrorManager);
        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private static function job(string $id, string $pipeline, ?string $start, string $contentReleaseId): array
    {
        return [
            'id' => $id,
            'pipeline' => $pipeline,
            'tasks' => [],
            'completed' => $start !== null,
            'canceled' => $start === null,
            'errored' => false,
            'created' => '2026-10-01T07:00:00+00:00',
            'start' => $start,
            'variables' => ['contentReleaseId' => $contentReleaseId],
            'user' => 'cli',
        ];
    }
}
