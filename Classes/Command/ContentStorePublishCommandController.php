<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Neos\Flow\Cli\CommandController;

final class ContentStorePublishCommandController extends CommandController
{
    public function __construct(
        private readonly ContentReleaseManager $contentReleaseManager,
    ) {
        parent::__construct();
    }

    public function allCommand(): void
    {
        $this->contentReleaseManager->cancelAllRunningContentReleases();
        $this->contentReleaseManager->startFullContentRelease();
    }
}
