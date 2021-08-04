<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\Dto\ContentReleaseDetails;
use Flowpack\DecoupledContentStore\BackendUi\Dto\ContentReleaseOverviewRow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingStatisticsStore;
use Flowpack\DecoupledContentStore\Utility\Sparkline;
use Flowpack\Prunner\ValueObject\JobId;
use Neos\Flow\Annotations as Flow;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\PipelineName;
use Neos\Fusion\Core\Cache\ContentCache;

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
     * @var RedisRenderingStatisticsStore
     */
    protected $redisRenderingStatisticsStore;

    public function loadBackendOverviewData()
    {
        $result = $this->prunnerApiService->loadPipelinesAndJobs();
        $contentReleaseJobs = $result->getJobs()->forPipeline(PipelineName::create('do_content_release'));


        $contentReleases = [];
        foreach ($contentReleaseJobs as $contentReleaseJob) {
            $variables = $contentReleaseJob->getVariables();
            if (isset($variables['contentReleaseId'])) {
                // TODO: only for legacy that the if can be false.
                $contentReleases[] = ContentReleaseIdentifier::fromString((string)$variables['contentReleaseId']);
            }
        }

        $counts = $this->redisEnumerationRepository->countMultiple(...$contentReleases);
        $renderingProgresses = $this->redisRenderingStatisticsStore->getMultipleRenderingProgress(...$contentReleases);

        $result = [];
        foreach ($contentReleaseJobs as $contentReleaseJob) {
            $variables = $contentReleaseJob->getVariables();
            if (isset($variables['contentReleaseId'])) {
                // TODO: only for legacy that the if can be false.
                $contentRelease = ContentReleaseIdentifier::fromString((string)$variables['contentReleaseId']);

                $result[] = new ContentReleaseOverviewRow(
                    $contentRelease,
                    $contentReleaseJob,
                    $counts->getResultForContentRelease($contentRelease),
                    $renderingProgresses->getResultForContentRelease($contentRelease)
                );
            }
        }

        return $result;
    }

    public function loadDetailsData(string $jobIdentifier): ContentReleaseDetails
    {
        $contentReleaseJob = $this->prunnerApiService->loadJobDetail(JobId::create($jobIdentifier));
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseJob->getVariables()['contentReleaseId']);

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