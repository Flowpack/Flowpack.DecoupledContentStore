<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\IncrementalContentReleaseCatchUpHook;
use Flowpack\DecoupledContentStore\IncrementalContentReleaseHandler;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests which content repository events start an incremental content release, and whether that happens after a web
 * request or after a CLI command.
 */
final class IncrementalContentReleaseCatchUpHookTest extends TestCase
{
    public function testAPublishIntoLiveStartsAReleaseAfterTheWebRequest(): void
    {
        $handler = $this->buildHandler(self::once());

        $this->catchUp($handler, SubscriptionStatus::ACTIVE, self::publishedInto(WorkspaceName::forLive()));
        $handler->startContentReleaseIfNodesWerePublishedBefore();
    }

    public function testAPublishIntoAnotherWorkspaceStartsNoRelease(): void
    {
        $handler = $this->buildHandler(self::never());

        $this->catchUp($handler, SubscriptionStatus::ACTIVE, self::publishedInto(WorkspaceName::fromString('review')));
        $handler->startContentReleaseIfNodesWerePublishedBefore();
    }

    public function testAPublishIntoLiveFromTheCliStartsNoRelease(): void
    {
        $handler = $this->buildHandler(self::never());

        $this->catchUp($handler, SubscriptionStatus::ACTIVE, self::publishedInto(WorkspaceName::forLive()));
        $handler->startContentReleaseIfLiveVisibilityChanged();
    }

    public function testReplayingHistoryStartsNoRelease(): void
    {
        $handler = $this->buildHandler(self::never());

        $this->catchUp(
            $handler,
            SubscriptionStatus::BOOTING,
            self::publishedInto(WorkspaceName::forLive()),
            self::tagged(WorkspaceName::forLive(), NeosSubtreeTag::disabled()),
        );
        $handler->startContentReleaseIfNodesWerePublishedBefore();
        $handler->startContentReleaseIfLiveVisibilityChanged();
    }

    public function testDisablingANodeInLiveFromTheCliStartsARelease(): void
    {
        // what timeableNodeVisibility:execute does once a disable date is reached
        $handler = $this->buildHandler(self::once());

        $this->catchUp(
            $handler,
            SubscriptionStatus::ACTIVE,
            self::tagged(WorkspaceName::forLive(), NeosSubtreeTag::disabled()),
        );
        $handler->startContentReleaseIfLiveVisibilityChanged();
    }

    public function testEnablingANodeInLiveFromTheCliStartsARelease(): void
    {
        $handler = $this->buildHandler(self::once());

        $this->catchUp(
            $handler,
            SubscriptionStatus::ACTIVE,
            self::untagged(WorkspaceName::forLive(), NeosSubtreeTag::disabled()),
        );
        $handler->startContentReleaseIfLiveVisibilityChanged();
    }

    public function testDisablingANodeInAUserWorkspaceStartsNoRelease(): void
    {
        $handler = $this->buildHandler(self::never());

        $this->catchUp(
            $handler,
            SubscriptionStatus::ACTIVE,
            self::tagged(WorkspaceName::fromString('user-editor'), NeosSubtreeTag::disabled()),
        );
        $handler->startContentReleaseIfLiveVisibilityChanged();
        $handler->startContentReleaseIfNodesWerePublishedBefore();
    }

    public function testOtherSubtreeTagsStartNoRelease(): void
    {
        $handler = $this->buildHandler(self::never());

        $this->catchUp(
            $handler,
            SubscriptionStatus::ACTIVE,
            self::tagged(WorkspaceName::forLive(), SubtreeTag::fromString('custom')),
        );
        $handler->startContentReleaseIfLiveVisibilityChanged();
    }

    public function testAPublishWhichAlsoChangesVisibilityStartsOnlyOneRelease(): void
    {
        // a publish copies the editor's SubtreeWasTagged events into live as well
        $handler = $this->buildHandler(self::once());

        $this->catchUp(
            $handler,
            SubscriptionStatus::ACTIVE,
            self::tagged(WorkspaceName::forLive(), NeosSubtreeTag::disabled()),
            self::publishedInto(WorkspaceName::forLive()),
        );
        $handler->startContentReleaseIfNodesWerePublishedBefore();
        $handler->startContentReleaseIfNodesWerePublishedBefore();
    }

    public function testNoReleaseIsStartedIfTheSettingIsOff(): void
    {
        $handler = $this->buildHandler(self::never(), false);

        $this->catchUp(
            $handler,
            SubscriptionStatus::ACTIVE,
            self::publishedInto(WorkspaceName::forLive()),
            self::tagged(WorkspaceName::forLive(), NeosSubtreeTag::disabled()),
        );
        $handler->startContentReleaseIfNodesWerePublishedBefore();
        $handler->startContentReleaseIfLiveVisibilityChanged();
    }

    private function catchUp(
        IncrementalContentReleaseHandler $handler,
        SubscriptionStatus $subscriptionStatus,
        EventInterface ...$events,
    ): void {
        // the hook never looks at the envelope
        $eventEnvelope = new ReflectionClass(EventEnvelope::class)->newInstanceWithoutConstructor();

        $hook = new IncrementalContentReleaseCatchUpHook($handler);
        $hook->onBeforeCatchUp($subscriptionStatus);
        foreach ($events as $event) {
            $hook->onBeforeEvent($event, $eventEnvelope);
            $hook->onAfterEvent($event, $eventEnvelope);
        }
        $hook->onAfterCatchUp();
    }

    private function buildHandler(
        InvocationOrder $expectedReleases,
        bool $startIncrementalReleaseOnWorkspacePublish = true,
    ): IncrementalContentReleaseHandler {
        $contentReleaseManager = $this->createMock(ContentReleaseManager::class);
        $contentReleaseManager->expects($expectedReleases)->method('startIncrementalContentRelease');

        return new IncrementalContentReleaseHandler($contentReleaseManager, $startIncrementalReleaseOnWorkspacePublish);
    }

    private static function publishedInto(WorkspaceName $targetWorkspaceName): WorkspaceWasPublished
    {
        return new WorkspaceWasPublished(
            WorkspaceName::fromString('user-editor'),
            $targetWorkspaceName,
            ContentStreamId::fromString('new-cs'),
            ContentStreamId::fromString('previous-cs'),
            false,
        );
    }

    private static function tagged(WorkspaceName $workspaceName, SubtreeTag $tag): SubtreeWasTagged
    {
        return new SubtreeWasTagged(
            $workspaceName,
            ContentStreamId::fromString('cs'),
            NodeAggregateId::fromString('page'),
            DimensionSpacePointSet::fromArray([]),
            $tag,
        );
    }

    private static function untagged(WorkspaceName $workspaceName, SubtreeTag $tag): SubtreeWasUntagged
    {
        return new SubtreeWasUntagged(
            $workspaceName,
            ContentStreamId::fromString('cs'),
            NodeAggregateId::fromString('page'),
            DimensionSpacePointSet::fromArray([]),
            $tag,
        );
    }
}
