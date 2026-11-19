<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
final class IncrementalContentReleaseHandler
{
    private bool $nodePublishedInThisRequest = false;

    private bool $liveVisibilityChangedInThisRequest = false;

    public function __construct(
        private readonly ContentReleaseManager $contentReleaseManager,
        #[Flow\InjectConfiguration('startIncrementalReleaseOnWorkspacePublish')]
        protected readonly bool $startIncrementalReleaseOnWorkspacePublish,
    ) {}

    public function nodePublished(): void
    {
        $this->nodePublishedInThisRequest = true;
    }

    public function liveVisibilityChanged(): void
    {
        $this->liveVisibilityChangedInThisRequest = true;
    }

    /**
     * Called after a web request.
     */
    public function startContentReleaseIfNodesWerePublishedBefore(): void
    {
        if ($this->nodePublishedInThisRequest || $this->liveVisibilityChangedInThisRequest) {
            $this->startContentRelease();
        }
    }

    /**
     * Called after a CLI command. Publishing from the CLI does not start a release, but enabling or disabling nodes
     * in live does: that is what timeableNodeVisibility:execute does when a page's enable or disable date is reached.
     */
    public function startContentReleaseIfLiveVisibilityChanged(): void
    {
        if ($this->liveVisibilityChangedInThisRequest) {
            $this->startContentRelease();
        }
    }

    private function startContentRelease(): void
    {
        $this->nodePublishedInThisRequest = false;
        $this->liveVisibilityChangedInThisRequest = false;
        if ($this->startIncrementalReleaseOnWorkspacePublish) {
            $this->contentReleaseManager->startIncrementalContentRelease();
        }
    }
}
