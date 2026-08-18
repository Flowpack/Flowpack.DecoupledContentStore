<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Exception\QuickContentReleaseNotPossibleException;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\Prunner\Dto\Job;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\JobId;
use Flowpack\Prunner\ValueObject\PipelineName;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Security\Context;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class ContentReleaseManager
{
    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\InjectConfiguration("configEpoch")
     * @var array
     */
    protected $configEpochSettings;

    /**
     * @FLow\Inject
     * @var Context
     */
    protected $securityContext;

    #[Flow\Inject]
    protected AutomaticReleaseSwitchService $automaticReleaseSwitchService;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    const REDIS_CURRENT_RELEASE_KEY = 'contentStore:current';
    const NO_PREVIOUS_RELEASE = 'NO_PREVIOUS_RELEASE';
    const CONTENT_RELEASE_PIPELINE_NAME = 'do_content_release';
    const QUICK_CONTENT_RELEASE_PIPELINE_NAME = 'do_quick_content_release';

    /**
     * All automatic release triggers (workspace publish, asset change, re-release after a rendering error) run through
     * this method, so it is the single place where the pause switch takes effect.
     *
     * While automatic releases are paused, the returned identifier belongs to a release which was never scheduled.
     * No caller uses the return value today; the signature is kept so existing callers do not have to change.
     */
    public function startIncrementalContentRelease(
        ?string $currentContentReleaseId = null,
        ?Workspace $workspace = null,
        array $additionalVariables = []
    ): ContentReleaseIdentifier {
        $contentReleaseId = ContentReleaseIdentifier::create();

        if ($this->automaticReleaseSwitchService->isPaused()) {
            $this->automaticReleaseSwitchService->countSuppressedRelease();
            $this->logger->info(
                sprintf(
                    'Automatic content releases are paused, so content release %s was not scheduled.',
                    $contentReleaseId->getIdentifier()
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );

            return $contentReleaseId;
        }

        // the currentContentReleaseId is not used in any pipeline step in this package, but is a common need in other
        // use cases in extensions, e.g. calculating the differences between current and new release
        $this->prunnerApiService->schedulePipeline(
            PipelineName::create(self::CONTENT_RELEASE_PIPELINE_NAME),
            array_merge($additionalVariables, [
                'contentReleaseId' => $contentReleaseId,
                'currentContentReleaseId' => $this->resolveCurrentContentReleaseId($currentContentReleaseId),
                'validate' => true,
                'flushContentCache' => false,
                'workspaceName' => $workspace !== null ? $workspace->getName() : 'live',
                'accountId' => $this->getAccountId()
            ])
        );

        return $contentReleaseId;
    }

    // the validate parameter can be used to intentionally skip the validation step for this release
    //
    // This is the explicitly requested release ("Publish All"), so it is deliberately not affected by the pause switch.
    public function startFullContentRelease(
        bool $validate = true,
        ?string $currentContentReleaseId = null,
        ?Workspace $workspace = null,
        array $additionalVariables = []
    ): ContentReleaseIdentifier {
        $contentReleaseId = ContentReleaseIdentifier::create();
        $this->prunnerApiService->schedulePipeline(
            PipelineName::create(self::CONTENT_RELEASE_PIPELINE_NAME),
            array_merge($additionalVariables, [
                'contentReleaseId' => $contentReleaseId,
                'currentContentReleaseId' => $this->resolveCurrentContentReleaseId($currentContentReleaseId),
                'validate' => $validate,
                'flushContentCache' => true,
                'workspaceName' => $workspace !== null ? $workspace->getName() : 'live',
                'accountId' => $this->getAccountId()
            ])
        );

        return $contentReleaseId;
    }

    /**
     * Publish the given document nodes into a copy of the release which is currently live, instead of rendering
     * every document again.
     *
     * This is an explicitly requested release, so like "Publish All" it is deliberately not affected by the pause
     * switch - a paused automatic release is in fact the situation this exists for.
     *
     * @param array<string, mixed> $additionalVariables additional prunner variables, e.g. for added pipeline tasks
     * @throws QuickContentReleaseNotPossibleException if there is no release to build upon, or another quick release
     *                                                 is still on its way
     */
    public function startQuickContentRelease(
        NodeIdentifiers $nodeIdentifiers,
        ?Workspace $workspace = null,
        array $additionalVariables = []
    ): ContentReleaseIdentifier {
        $currentContentReleaseId = $this->resolveCurrentContentReleaseId(null);
        if ($currentContentReleaseId === self::NO_PREVIOUS_RELEASE) {
            throw new QuickContentReleaseNotPossibleException(
                'There is no content release live at the moment, so there is nothing to publish the given nodes into. '
                . 'Run a full content release instead.',
                1786963710
            );
        }

        // a quick release copies the release which is live when it is scheduled, so a second one queued behind the
        // first would build on the release the first is about to replace - and drop that first change without a word
        $quickReleaseJobs = $this->prunnerApiService
            ->loadPipelinesAndJobs()
            ->getJobs()
            ->forPipeline(PipelineName::create(self::QUICK_CONTENT_RELEASE_PIPELINE_NAME));
        // Jobs::waiting() means "never started", which is true of a job cancelled while it was still queued as well.
        // Such a job stays in prunner's list until it falls out of the pipeline's retention_count - a window only
        // quick releases consume - so without the isCompleted() guard one cancelled job blocks them all until then.
        $queuedQuickReleaseJobs = $quickReleaseJobs
            ->waiting()
            ->filter(static fn(Job $job): bool => !$job->isCompleted());
        if ($quickReleaseJobs->running()->getArray() !== [] || $queuedQuickReleaseJobs->getArray() !== []) {
            throw new QuickContentReleaseNotPossibleException(
                'Another quick content release is still on its way. Wait for it to go live, then publish these nodes.',
                1786963711
            );
        }

        $contentReleaseId = ContentReleaseIdentifier::create();
        $this->prunnerApiService->schedulePipeline(
            PipelineName::create(self::QUICK_CONTENT_RELEASE_PIPELINE_NAME),
            array_merge($additionalVariables, [
                'contentReleaseId' => $contentReleaseId,
                'currentContentReleaseId' => $currentContentReleaseId,
                'quickPublishNodeIdentifiers' => (string) $nodeIdentifiers,
                'workspaceName' => $workspace !== null ? $workspace->getName() : 'live',
                'accountId' => $this->getAccountId()
            ])
        );

        return $contentReleaseId;
    }

    public function cancelAllRunningContentReleases(): void
    {
        foreach ($this->runningContentReleaseJobs() as $job) {
            $this->prunnerApiService->cancelJob($job);
        }
    }

    /**
     * Cancel a single running content release ignoring all others
     */
    public function cancelRunningContentRelease(JobId $jobId): void
    {
        foreach ($this->runningContentReleaseJobs() as $job) {
            if ($job->getId() === $jobId) {
                $this->prunnerApiService->cancelJob($job);
                break;
            }
        }
    }

    /**
     * Both pipelines build a release which ends up being switched live, so both are cancelled.
     *
     * @return Job[]
     */
    private function runningContentReleaseJobs(): array
    {
        $jobs = $this->prunnerApiService->loadPipelinesAndJobs()->getJobs();

        return array_merge(
            $jobs->forPipeline(PipelineName::create(self::CONTENT_RELEASE_PIPELINE_NAME))->running()->getArray(),
            $jobs->forPipeline(PipelineName::create(self::QUICK_CONTENT_RELEASE_PIPELINE_NAME))->running()->getArray()
        );
    }

    public function toggleConfigEpoch(RedisInstanceIdentifier $redisInstanceIdentifier): void
    {
        $currentConfigEpochConfig = $this->configEpochSettings['current'] ?? null;
        $previousConfigEpochConfig = $this->configEpochSettings['previous'] ?? null;
        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $configEpochRedis = $redis->get('contentStore:configEpoch');

        if ($configEpochRedis === $currentConfigEpochConfig) {
            $redis->set('contentStore:configEpoch', $previousConfigEpochConfig);
        } else {
            $redis->set('contentStore:configEpoch', $currentConfigEpochConfig);
        }
    }

    private function resolveCurrentContentReleaseId(?string $currentContentReleaseId): string
    {
        if ($currentContentReleaseId !== null) {
            return $currentContentReleaseId;
        }

        $redis = $this->redisClientManager->getPrimaryRedis();
        $currentContentReleaseIdFromRedis = $redis->get(self::REDIS_CURRENT_RELEASE_KEY);

        if ($currentContentReleaseIdFromRedis !== false) {
            return $currentContentReleaseIdFromRedis;
        }

        return self::NO_PREVIOUS_RELEASE;
    }

    private function getAccountId(): ?string
    {
        if ($this->securityContext->isInitialized() && !is_null($this->securityContext->getAccount())) {
            return $this->securityContext->getAccount()->getAccountIdentifier();
        }

        return null;
    }
}
