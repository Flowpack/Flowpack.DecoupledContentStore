<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\Dto\ContentReleaseDetails;
use Flowpack\DecoupledContentStore\BackendUi\Dto\ContentReleaseOverviewRow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingStatisticsStore;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Neos\Flow\Annotations as Flow;
use Flowpack\Prunner\PrunnerApiService;

/**
 * @Flow\Scope("singleton")
 */
class BackendUiDataService
{
    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var RedisEnumerationRepository
     */
    protected $redisEnumerationRepository;

    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    /**
     * @Flow\Inject
     * @var RedisRenderingStatisticsStore
     */
    protected $redisRenderingStatisticsStore;

    /**
     * @Flow\Inject
     * @var RedisRenderingErrorManager
     */
    protected $redisRenderingErrorManager;

    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;

    public function loadBackendOverviewData(RedisInstanceIdentifier $redisInstanceIdentifier)
    {
        $contentReleaseIds = $this->redisContentReleaseService->fetchAllReleaseIds($redisInstanceIdentifier);
        $metadata = $this->redisContentReleaseService->fetchMetadataForContentReleases($redisInstanceIdentifier, ...$contentReleaseIds);
        $counts = $this->redisEnumerationRepository->countMultiple($redisInstanceIdentifier, ...$contentReleaseIds);
        $iterationsCounts = $this->redisRenderingStatisticsStore->countMultipleRenderingStatistics($redisInstanceIdentifier, ...$contentReleaseIds);
        $errorCounts = $this->redisRenderingErrorManager->countMultipleErrors($redisInstanceIdentifier, ...$contentReleaseIds);
        $lastRenderingStatisticsEntries = $this->redisRenderingStatisticsStore->getLastRenderingStatisticsEntry($redisInstanceIdentifier, ...$contentReleaseIds);
        $firstRenderingStatisticsEntries = $this->redisRenderingStatisticsStore->getFirstRenderingStatisticsEntry($redisInstanceIdentifier, ...$contentReleaseIds);

        $result = [];
        foreach ($contentReleaseIds as $contentReleaseId) {
            $lastRendering = RenderingStatistics::fromJsonString($lastRenderingStatisticsEntries->getResultForContentRelease($contentReleaseId));
            $firstRendering = RenderingStatistics::fromJsonString($firstRenderingStatisticsEntries->getResultForContentRelease($contentReleaseId));

            $result[] = new ContentReleaseOverviewRow(
                $contentReleaseId,
                $metadata->getResultForContentRelease($contentReleaseId),
                $counts->getResultForContentRelease($contentReleaseId),
                $iterationsCounts->getResultForContentRelease($contentReleaseId),
                $errorCounts->getResultForContentRelease($contentReleaseId),
                $lastRendering->getTotalJobs() > 0 ? round($lastRendering->getRenderedJobs()
                    / $lastRendering->getTotalJobs() * 100) : 100,
                $firstRendering->getRenderedJobs(),
                $contentReleaseId->equals($this->redisReleaseSwitchService->getCurrentRelease($redisInstanceIdentifier))
            );
        }

        return $result;
    }

    public function loadDetailsData(ContentReleaseIdentifier $contentReleaseIdentifier, RedisInstanceIdentifier $redisInstanceIdentifier): ContentReleaseDetails
    {
        $contentReleaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier, $redisInstanceIdentifier);
        $contentReleaseJob = $this->prunnerApiService->loadJobDetail($contentReleaseMetadata->getPrunnerJobId()->toJobId());

        $manualTransferJobs = count($contentReleaseMetadata->getmanualTransferJobIds()) ? array_map(function (PrunnerJobId $item) {
            return $this->prunnerApiService->loadJobDetail($item->toJobId());
        }, $contentReleaseMetadata->getmanualTransferJobIds()) : [];

        $renderingStatistics = array_map(function(string $item) {
            return RenderingStatistics::fromJsonString($item);
        }, $this->redisRenderingStatisticsStore->getRenderingStatistics($contentReleaseIdentifier, $redisInstanceIdentifier));

        $renderingErrorCount = count($this->redisRenderingErrorManager->getRenderingErrors($contentReleaseIdentifier, $redisInstanceIdentifier));

        $currentReleaseIdentifier = $this->redisReleaseSwitchService->getCurrentRelease($redisInstanceIdentifier);

        return new ContentReleaseDetails(
            $contentReleaseIdentifier,
            $contentReleaseJob,
            $this->redisEnumerationRepository->count($contentReleaseIdentifier),
            $renderingStatistics,
            $renderingErrorCount,
            $contentReleaseIdentifier->equals($currentReleaseIdentifier),
            $manualTransferJobs
        );
    }
}
