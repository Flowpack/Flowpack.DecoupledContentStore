<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\Prunner\Dto\Job;
use Neos\Flow\Annotations as Flow;

/**
 * The status the backend shows for a release.
 *
 * The status in the release metadata reads "success" as soon as rendering is done, while validation, transfer and
 * switch are still ahead. "failed" is reliable: the render orchestrator and the pipeline's on_error hook set it. So
 * the prunner job, which spans the whole pipeline, decides wherever it is known.
 */
#[Flow\Proxy(false)]
enum ContentReleaseStatus: string
{
    case SCHEDULED = 'scheduled';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case ABORTED = 'aborted';
    /**
     * Prunner cannot be reached, or the release has no metadata to find its job by. The rendering status is not
     * shown instead, as it reads "success" for a release which is still validating.
     */
    case UNKNOWN = 'unknown';

    public static function fromJob(Job $job, ?ContentReleaseMetadata $metadata): self
    {
        if ($job->isCanceled()) {
            return self::CANCELED;
        }
        if ($job->isErrored() || ($metadata?->getStatus()->isFailed() ?? false)) {
            return self::FAILED;
        }
        if ($job->isCompleted()) {
            return self::SUCCESS;
        }
        return $job->getStart() === null ? self::SCHEDULED : self::RUNNING;
    }

    /**
     * Prunner answered, but no longer holds the job: it has fallen out of the pipeline's retention, so the release is
     * not being built anymore. One which was switched live succeeded; one which was neither switched nor marked
     * failed was cancelled or killed with its worker.
     */
    public static function jobGone(ContentReleaseMetadata $metadata): self
    {
        if ($metadata->getStatus()->isFailed()) {
            return self::FAILED;
        }
        return $metadata->getSwitchTime() !== null ? self::SUCCESS : self::ABORTED;
    }

    /**
     * A getter, so Fusion reads it as the property "badgeClass": Eel only calls methods of objects which allow it.
     */
    public function getBadgeClass(): string
    {
        return match ($this) {
            self::SUCCESS => 'neos-badge neos-badge-success',
            self::FAILED => 'neos-badge neos-badge-important',
            self::SCHEDULED, self::RUNNING, self::CANCELED, self::ABORTED, self::UNKNOWN => 'neos-badge',
        };
    }
}
