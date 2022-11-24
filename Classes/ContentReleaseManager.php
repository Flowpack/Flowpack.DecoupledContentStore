<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\Prunner\ValueObject\JobId;
use Neos\Flow\Annotations as Flow;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\PipelineName;
use Neos\Fusion\Core\Cache\ContentCache;

/**
 * @Flow\Scope("singleton")
 */
class ContentReleaseManager
{
    /**
     * @Flow\Inject
     * @var ContentCache
     */
    protected $contentCache;

    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\InjectConfiguration("configEpoch")
     * @var array
     */
    protected $configEpochSettings;

    const REDIS_CURRENT_RELEASE_KEY = 'contentStore:current';
    const NO_PREVIOUS_RELEASE = 'NO_PREVIOUS_RELEASE';

    public function startIncrementalContentRelease(string $currentContentReleaseId = null): ContentReleaseIdentifier
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        if ($currentContentReleaseId) {
            $currentContentReleaseId = $redis->get(self::REDIS_CURRENT_RELEASE_KEY);
        }

        $contentReleaseId = ContentReleaseIdentifier::create();
        // the currentContentReleaseId is not used in any pipeline step in this package, but is a common need in other
        // use cases in extensions, e.g. calculating the differences between current and new release
        $this->prunnerApiService->schedulePipeline(PipelineName::create('do_content_release'), ['contentReleaseId' => $contentReleaseId, 'currentContentReleaseId' => $currentContentReleaseId ?: self::NO_PREVIOUS_RELEASE, 'validate' => true]);
        return $contentReleaseId;
    }

    // the validate parameter can be used to intentionally skip the validation step for this release
    public function startFullContentRelease(bool $validate = true, string $currentContentReleaseId = null): ContentReleaseIdentifier
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        if ($currentContentReleaseId) {
            $currentContentReleaseId = $redis->get(self::REDIS_CURRENT_RELEASE_KEY);
        }

        $contentReleaseId = ContentReleaseIdentifier::create();
        $this->contentCache->flush();
        $this->prunnerApiService->schedulePipeline(PipelineName::create('do_content_release'), ['contentReleaseId' => $contentReleaseId, 'currentContentReleaseId' => $currentContentReleaseId ?: self::NO_PREVIOUS_RELEASE, 'validate' => $validate]);
        return $contentReleaseId;
    }

    public function cancelAllRunningContentReleases()
    {
        $result = $this->prunnerApiService->loadPipelinesAndJobs();
        $runningJobs = $result->getJobs()->forPipeline(PipelineName::create('do_content_release'))->running();
        foreach ($runningJobs as $job) {
            $this->prunnerApiService->cancelJob($job);
        }
    }

    public function cancelRunningContentReleases(JobId $jobId): void
    {
        $result = $this->prunnerApiService->loadPipelinesAndJobs();
        $runningJobs = $result->getJobs()->forPipeline(PipelineName::create('do_content_release'))->running();
        foreach ($runningJobs as $job) {
            if ($job->getId() !== $jobId) {
                $this->prunnerApiService->cancelJob($job);
                break;
            }
        }
    }

    public function toggleConfigEpoch(RedisInstanceIdentifier $redisInstanceIdentifier)
    {
        $currentConfigEpochConfig = $this->configEpochSettings['current'] ?? null;
        $previousConfigEpochConfig = $this->configEpochSettings['previous'] ?? null;
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $configEpochRedis = $redis->get('contentStore:configEpoch');

        if ($configEpochRedis === $currentConfigEpochConfig) {
            $redis->set('contentStore:configEpoch', $previousConfigEpochConfig);
        } else {
            $redis->set('contentStore:configEpoch', $currentConfigEpochConfig);
        }
    }
}
