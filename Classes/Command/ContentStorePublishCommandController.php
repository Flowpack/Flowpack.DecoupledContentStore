<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

class ContentStorePublishCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    public function allCommand()
    {
        $this->contentReleaseManager->cancelAllRunningContentReleases();
        $this->contentReleaseManager->startFullContentRelease();
    }

}
