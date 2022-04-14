<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
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

    const REDIS_CURRENT_RELEASE_KEY = 'contentStore:current';
    const NO_PREVIOUS_RELEASE = 'NO_PREVIOUS_RELEASE';

    public function startIncrementalContentRelease()
    {
        $redis = $this->redisClientManager->getPrimaryRedis();
        $currentContentReleaseId = $redis->get(self::REDIS_CURRENT_RELEASE_KEY);

        // the currentContentReleaseId is not used in any pipeline step in this package, but is a common need in other
        // use cases in extensions, e.g. calculating the differences between current and new release
        $this->prunnerApiService->schedulePipeline(PipelineName::create('do_content_release'), ['contentReleaseId' => (string)time(), 'currentContentReleaseId' => $currentContentReleaseId ?: self::NO_PREVIOUS_RELEASE]);
    }

    public function startFullContentRelease()
    {
        $this->contentCache->flush();
        $this->prunnerApiService->schedulePipeline(PipelineName::create('do_content_release'), ['contentReleaseId' => (string)time(), 'currentContentReleaseId' => self::NO_PREVIOUS_RELEASE]);
    }

    public function cancelAllRunningContentReleases()
    {
        $result = $this->prunnerApiService->loadPipelinesAndJobs();
        $runningJobs = $result->getJobs()->forPipeline(PipelineName::create('do_content_release'))->running();
        foreach ($runningJobs as $job) {
            $this->prunnerApiService->cancelJob($job);
        }
    }
}
