<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\Flow\Annotations as Flow;

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
     * @Flow\InjectConfiguration("startIncrementalReleaseOnWorkspacePublish")
     */
    protected $startIncrementalReleaseOnWorkspacePublish;

    protected $nodePublishedInThisRequest = false;

    public function nodePublished()
    {
        $this->nodePublishedInThisRequest = true;
    }

    public function startContentReleaseIfNodesWerePublishedBefore()
    {
        if ($this->startIncrementalReleaseOnWorkspacePublish === true && $this->nodePublishedInThisRequest === true) {
            $this->contentReleaseManager->startIncrementalContentRelease();
        }
    }
}
