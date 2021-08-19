<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\Dto\ContentReleaseDetails;
use Flowpack\DecoupledContentStore\BackendUi\Dto\ContentReleaseOverviewRow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingStatisticsStore;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\Utility\Sparkline;
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

    public function loadBackendOverviewData()
    {
        $contentReleases = $this->redisContentReleaseService->fetchAllReleaseIds();

        $counts = $this->redisEnumerationRepository->countMultiple(...$contentReleases);
        $metadata = $this->redisContentReleaseService->fetchMetadataForContentReleases(...$contentReleases);
        $renderingProgresses = $this->redisRenderingStatisticsStore->getMultipleRenderingProgress(...$contentReleases);
        $counts = $this->redisEnumerationRepository->countMultiple(...$contentReleases);

        $result = [];
        foreach ($contentReleases as $contentRelease) {
            $result[] = new ContentReleaseOverviewRow(
                $contentRelease,
                $metadata->getResultForContentRelease($contentRelease),
                $counts->getResultForContentRelease($contentRelease),
                $renderingProgresses->getResultForContentRelease($contentRelease)
            );
        }

        return $result;
    }

    public function loadDetailsData(ContentReleaseIdentifier $contentReleaseIdentifier): ContentReleaseDetails
    {
        $contentReleaseMetadata = $this->redisContentReleaseService->fetchMetadataForContentRelease($contentReleaseIdentifier);
        $contentReleaseJob = $this->prunnerApiService->loadJobDetail($contentReleaseMetadata->getPrunnerJobId()->toJobId());

        $renderingsPerSecond = $this->redisRenderingStatisticsStore->getRenderingsPerSecondSamples($contentReleaseIdentifier);

        return new ContentReleaseDetails(
            $contentReleaseIdentifier,
            $contentReleaseJob,
            $this->redisEnumerationRepository->count($contentReleaseIdentifier),
            $this->redisRenderingStatisticsStore->getRenderingProgress($contentReleaseIdentifier),
            Sparkline::sparkline('', $renderingsPerSecond)
        );
    }
}