<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Metrics;

use DateTimeImmutable;
use Exception;
use Flowpack\DecoupledContentStore\BackendUi\Dto\WorkerErrorLog;
use Flowpack\DecoupledContentStore\BackendUi\WorkerErrorLogAggregator;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Metrics\Dto\ContentReleaseMetrics;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingTimeStatisticsStore;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\Prunner\PrunnerApiService;
use Neos\Flow\Annotations as Flow;
use Throwable;

/**
 * Reads the numbers a finished (or failed) content release left in Redis, so they can be shipped somewhere
 * which keeps them longer than Redis keeps the release.
 */
#[Flow\Scope('singleton')]
class ContentReleaseMetricsService
{
    #[Flow\Inject]
    protected RedisContentReleaseService $redisContentReleaseService;

    #[Flow\Inject]
    protected RedisEnumerationRepository $redisEnumerationRepository;

    #[Flow\Inject]
    protected RedisRenderingTimeStatisticsStore $redisRenderingStatisticsStore;

    #[Flow\Inject]
    protected RedisRenderingErrorManager $redisRenderingErrorManager;

    #[Flow\Inject]
    protected WorkerErrorLogAggregator $workerErrorLogAggregator;

    #[Flow\Inject]
    protected PrunnerApiService $prunnerApiService;

    /**
     * Returns NULL when the release has no metadata - it was never created, or it has already been pruned.
     * @throws Exception
     */
    public function collectMetrics(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ?RedisInstanceIdentifier $redisInstanceIdentifier = null,
    ): ?ContentReleaseMetrics {
        $redisInstanceIdentifier = $redisInstanceIdentifier ?? RedisInstanceIdentifier::primary();

        $metadata = $this->redisContentReleaseService->fetchMetadataForContentRelease(
            $contentReleaseIdentifier,
            $redisInstanceIdentifier,
        );
        if ($metadata === null) {
            return null;
        }

        $renderingStatistics = array_map(
            fn(string $entry) => RenderingStatistics::fromJsonString($entry),
            $this->redisRenderingStatisticsStore->getRenderingStatistics(
                $contentReleaseIdentifier,
                $redisInstanceIdentifier,
            ),
        );

        return ContentReleaseMetrics::create(
            $contentReleaseIdentifier,
            $metadata,
            // the enumeration is only ever written on the primary instance, so this count ignores the instance
            $this->redisEnumerationRepository->count($contentReleaseIdentifier),
            $renderingStatistics,
            $this->redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier, $redisInstanceIdentifier),
            $this->collectWorkerErrorLogs($metadata),
            new DateTimeImmutable(),
        );
    }

    /**
     * The stacktrace of a failed render worker lives only in its prunner log, which is read over the prunner API.
     * That API can be unreachable exactly when a release went wrong, and losing the whole measurement over it
     * would defeat the purpose - so a failure is reported as "unknown" (NULL) rather than as an empty list.
     *
     * @return WorkerErrorLog[]|null
     */
    private function collectWorkerErrorLogs(ContentReleaseMetadata $metadata): ?array
    {
        try {
            $job = $this->prunnerApiService->loadJobDetail($metadata->getPrunnerJobId()->toJobId());
            if ($job === null) {
                return null;
            }

            return $this->workerErrorLogAggregator->aggregate($job);
        } catch (Throwable $throwable) {
            return null;
        }
    }
}
