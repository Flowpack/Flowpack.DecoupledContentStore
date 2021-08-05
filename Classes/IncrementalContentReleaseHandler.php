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
class IncrementalContentReleaseHandler
{
    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    protected $nodePublishedInThisRequest = false;

    public function nodePublished()
    {
        $this->nodePublishedInThisRequest = true;
    }

    public function startContentReleaseIfNotRunning()
    {
        if ($this->nodePublishedInThisRequest === true) {
            $this->contentReleaseManager->startIncrementalContentRelease();
        }
    }
}