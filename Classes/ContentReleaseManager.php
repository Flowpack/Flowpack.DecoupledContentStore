<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

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


    public function startContentRelease()
    {
        $this->prunnerApiService->schedulePipeline(PipelineName::create('do_content_release'), ['contentReleaseId' => (string)time()]);
    }

    public function startFullContentRelease()
    {
        $this->contentCache->flush();
        $this->prunnerApiService->schedulePipeline(PipelineName::create('do_content_release'), ['contentReleaseId' => (string)time()]);
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