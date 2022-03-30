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

    /**
     * @Flow\InjectConfiguration(path="autoPublish.onNodePublish")
     * @var string|null
     */
    protected $onNodePublish;

    protected $nodePublishedInThisRequest = false;

    public function nodePublished()
    {
        $this->nodePublishedInThisRequest = true;
    }

    public function startContentReleaseIfNodesWerePublishedBefore()
    {
        if ($this->nodePublishedInThisRequest === true) {
            switch ($this->onNodePublish) {
                case "incremental":
                    $this->contentReleaseManager->startIncrementalContentRelease();
                    return;
                case "full":
                    $this->contentReleaseManager->startFullContentRelease();
                    return;
            }
        }
    }
}
