<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;

final class IncrementalContentReleaseCatchUpHook implements CatchUpHookInterface
{
    /**
     * A booting subscription replays history, which must not trigger a content release.
     */
    private bool $isBooting = false;

    public function __construct(
        private readonly IncrementalContentReleaseHandler $incrementalContentReleaseHandler,
    ) {}

    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void
    {
        $this->isBooting = $subscriptionStatus === SubscriptionStatus::BOOTING;
    }

    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
    }

    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
        if (
            !$this->isBooting
            && $eventInstance instanceof WorkspaceWasPublished
            && $eventInstance->targetWorkspaceName->isLive()
        ) {
            $this->incrementalContentReleaseHandler->nodePublished();
        }
    }

    public function onAfterBatchCompleted(): void
    {
    }

    public function onAfterCatchUp(): void
    {
    }
}
