<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore;

use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
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
            $this->logger->info(sprintf('Automatic content releases are paused, so content release %s was not scheduled.', $contentReleaseId->getIdentifier()), LogEnvironment::fromMethodName(__METHOD__));

            return $contentReleaseId;
        }

        // the currentContentReleaseId is not used in any pipeline step in this package, but is a common need in other
        // use cases in extensions, e.g. calculating the differences between current and new release
        $this->prunnerApiService->schedulePipeline(
            PipelineName::create('do_content_release'),
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
            PipelineName::create('do_content_release'),
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

    public function cancelAllRunningContentReleases(): void
    {
        $result = $this->prunnerApiService->loadPipelinesAndJobs();
        $runningJobs = $result->getJobs()->forPipeline(PipelineName::create('do_content_release'))->running();
        foreach ($runningJobs as $job) {
            $this->prunnerApiService->cancelJob($job);
        }
    }

    /**
     * Cancel a single running content release ignoring all others
     */
    public function cancelRunningContentRelease(JobId $jobId): void
    {
        $result = $this->prunnerApiService->loadPipelinesAndJobs();
        $runningJobs = $result->getJobs()->forPipeline(PipelineName::create('do_content_release'))->running();
        foreach ($runningJobs as $job) {
            if ($job->getId() === $jobId) {
                $this->prunnerApiService->cancelJob($job);
                break;
            }
        }
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
