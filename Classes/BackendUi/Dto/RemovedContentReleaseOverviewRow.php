<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\Prunner\Dto\Job;
use Neos\Flow\Annotations as Flow;

/**
 * A release which Redis no longer holds, listed through the prunner job which built it.
 *
 * The metadata is only there while the keys a removed release keeps have not expired yet; without it, the job is all
 * that is known about the release.
 */
#[Flow\Proxy(false)]
final class RemovedContentReleaseOverviewRow
{
    public function __construct(
        private readonly ContentReleaseIdentifier $contentReleaseIdentifier,
        private readonly Job $job,
        private readonly ?ContentReleaseMetadata $metadata,
        private readonly int $errorCount,
    ) {}

    public function getContentReleaseIdentifier(): ContentReleaseIdentifier
    {
        return $this->contentReleaseIdentifier;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getMetadata(): ?ContentReleaseMetadata
    {
        return $this->metadata;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getStatus(): string
    {
        if ($this->metadata !== null) {
            return $this->metadata->getStatus()->getDisplayName();
        }
        if ($this->job->isCanceled()) {
            return 'canceled';
        }
        if ($this->job->isErrored()) {
            return 'failed';
        }
        return $this->job->isCompleted() ? 'done' : 'running';
    }
}
