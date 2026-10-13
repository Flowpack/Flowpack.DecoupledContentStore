<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\ContentReleaseStatus;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\Prunner\Dto\Job;
use Neos\Flow\Tests\UnitTestCase;

final class ContentReleaseStatusTest extends UnitTestCase
{
    public function testARenderedReleaseWhichIsStillValidatingIsRunning(): void
    {
        self::assertSame(
            ContentReleaseStatus::RUNNING,
            ContentReleaseStatus::fromJob(
                self::job(false, false, false),
                self::metadata(NodeRenderingCompletionStatus::success()),
            ),
        );
    }

    public function testAReleaseWhoseJobErroredOutsideRenderingIsFailed(): void
    {
        self::assertSame(
            ContentReleaseStatus::FAILED,
            ContentReleaseStatus::fromJob(
                self::job(true, false, true),
                self::metadata(NodeRenderingCompletionStatus::running()),
            ),
        );
    }

    public function testAJobCancelledByAPrunnerRestartIsCanceled(): void
    {
        // prunner flags such a job cancelled on boot but never gives it an end, so it is not completed either
        self::assertSame(
            ContentReleaseStatus::CANCELED,
            ContentReleaseStatus::fromJob(
                self::job(false, true, false),
                self::metadata(NodeRenderingCompletionStatus::running()),
            ),
        );
    }

    public function testACompletedJobIsSuccess(): void
    {
        self::assertSame(
            ContentReleaseStatus::SUCCESS,
            ContentReleaseStatus::fromJob(
                self::job(true, false, false),
                self::metadata(NodeRenderingCompletionStatus::success()),
            ),
        );
    }

    public function testASwitchedReleaseWhoseJobIsGoneIsSuccess(): void
    {
        self::assertSame(
            ContentReleaseStatus::SUCCESS,
            ContentReleaseStatus::jobGone(
                self::metadata(NodeRenderingCompletionStatus::success())->withSwitchTime(new \DateTimeImmutable()),
            ),
        );
    }

    public function testAReleaseMarkedFailedAfterItWasSwitchedIsFailed(): void
    {
        // e.g. sending the events to Spryker failed after the switch
        self::assertSame(
            ContentReleaseStatus::FAILED,
            ContentReleaseStatus::jobGone(
                self::metadata(NodeRenderingCompletionStatus::failed())->withSwitchTime(new \DateTimeImmutable()),
            ),
        );
    }

    public function testARenderedButNeverSwitchedReleaseWhoseJobIsGoneIsAborted(): void
    {
        // killed together with prunner during validation, so no on_error hook marked it failed
        self::assertSame(
            ContentReleaseStatus::ABORTED,
            ContentReleaseStatus::jobGone(self::metadata(NodeRenderingCompletionStatus::success())),
        );
    }

    public function testAnUnfinishedReleaseWhoseJobIsGoneIsAborted(): void
    {
        self::assertSame(
            ContentReleaseStatus::ABORTED,
            ContentReleaseStatus::jobGone(self::metadata(NodeRenderingCompletionStatus::running())),
        );
    }

    public function testOnlySuccessAndFailureAreColoured(): void
    {
        self::assertSame('neos-badge neos-badge-success', ContentReleaseStatus::SUCCESS->getBadgeClass());
        self::assertSame('neos-badge neos-badge-important', ContentReleaseStatus::FAILED->getBadgeClass());
        self::assertSame('neos-badge', ContentReleaseStatus::RUNNING->getBadgeClass());
    }

    private static function metadata(NodeRenderingCompletionStatus $status): ContentReleaseMetadata
    {
        return ContentReleaseMetadata::create(PrunnerJobId::fromString('job'), new \DateTimeImmutable())
            ->withStatus($status);
    }

    private static function job(bool $completed, bool $canceled, bool $errored): Job
    {
        return Job::fromJsonArray([
            'id' => 'job',
            'pipeline' => 'do_content_release',
            'tasks' => [],
            'completed' => $completed,
            'canceled' => $canceled,
            'errored' => $errored,
            'created' => '2026-10-01T07:00:00+00:00',
            'start' => '2026-10-01T08:00:00+00:00',
            'variables' => ['contentReleaseId' => '80'],
            'user' => 'cli',
        ]);
    }
}
