<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Exception\QuickContentReleaseNotPossibleException;
use Flowpack\DecoupledContentStore\QuickPublish\Dto\NodeIdentifiers;
use Flowpack\Prunner\Dto\PipelinesAndJobsResponse;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\JobId;
use Flowpack\Prunner\ValueObject\PipelineName;
use Neos\Flow\Security\Context;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Tests which release the manager schedules, and when it refuses to.
 *
 * All automatic triggers (workspace publish, asset change, re-release after a rendering error) go through
 * startIncrementalContentRelease(), so that method is the only gate for the pause switch. "Publish All" and a quick
 * release are explicit requests and must stay unaffected - otherwise the pause could not be used to prepare a
 * release by hand.
 */
class ContentReleaseManagerTest extends UnitTestCase
{
    private const NODE_IDENTIFIER = '3239baee-3e7f-785c-0853-f4302ef32570';

    private PrunnerApiService&MockObject $prunnerApiService;

    private AutomaticReleaseSwitchService&MockObject $automaticReleaseSwitchService;

    /**
     * what contentStore:current holds - false is redis' answer for a key which does not exist
     */
    private string|false $currentContentReleaseId = false;

    protected function setUp(): void
    {
        $this->prunnerApiService = $this->createMock(PrunnerApiService::class);
        $this->prunnerApiService->method('schedulePipeline')->willReturn(JobId::create('job-id'));

        $this->automaticReleaseSwitchService = $this->createMock(AutomaticReleaseSwitchService::class);
    }

    public function testAnAutomaticReleaseIsNotScheduledWhilePaused(): void
    {
        $this->automaticReleaseSwitchService->method('isPaused')->willReturn(true);
        $this->prunnerApiService->expects(self::never())->method('schedulePipeline');

        $this->buildContentReleaseManager()->startIncrementalContentRelease();
    }

    public function testASuppressedReleaseIsCountedSoTheBackendCanShowHowMuchIsWaiting(): void
    {
        $this->automaticReleaseSwitchService->method('isPaused')->willReturn(true);
        $this->automaticReleaseSwitchService->expects(self::once())->method('countSuppressedRelease');

        $this->buildContentReleaseManager()->startIncrementalContentRelease();
    }

    public function testAnAutomaticReleaseIsScheduledWhileNotPaused(): void
    {
        $this->automaticReleaseSwitchService->method('isPaused')->willReturn(false);
        $this->automaticReleaseSwitchService->expects(self::never())->method('countSuppressedRelease');
        $this->prunnerApiService->expects(self::once())->method('schedulePipeline');

        $this->buildContentReleaseManager()->startIncrementalContentRelease();
    }

    public function testPublishAllIsScheduledEvenWhilePaused(): void
    {
        $this->automaticReleaseSwitchService->method('isPaused')->willReturn(true);
        $this->prunnerApiService->expects(self::once())->method('schedulePipeline');

        $this->buildContentReleaseManager()->startFullContentRelease();
    }

    public function testAQuickReleaseIsScheduledEvenWhilePaused(): void
    {
        // pausing the automatic release is what quick releases exist for, so the pause must not block them
        $this->currentContentReleaseId = '5';
        $this->automaticReleaseSwitchService->method('isPaused')->willReturn(true);
        $this->prunnerApiService->expects(self::once())->method('schedulePipeline')->with(
            self::equalTo(PipelineName::create('do_quick_content_release')),
            self::callback(static fn(array $variables): bool =>
                $variables['currentContentReleaseId'] === '5'
                && $variables['quickPublishNodeIdentifiers'] === self::NODE_IDENTIFIER)
        );

        $this->buildContentReleaseManager()->startQuickContentRelease($this->nodeIdentifiers());
    }

    public function testAQuickReleaseIsRefusedWhileNoReleaseIsLive(): void
    {
        // there is nothing to copy, so the release would hold the given nodes and nothing else
        $this->currentContentReleaseId = false;
        $this->prunnerApiService->expects(self::never())->method('schedulePipeline');

        $this->expectException(QuickContentReleaseNotPossibleException::class);
        $this->buildContentReleaseManager()->startQuickContentRelease($this->nodeIdentifiers());
    }

    /**
     * @dataProvider quickReleasesOnTheirWay
     */
    public function testAQuickReleaseIsRefusedWhileAnotherOneIsOnItsWay(bool $started): void
    {
        // the second one copies the release the first is about to replace, so it would undo the first change
        $this->currentContentReleaseId = '5';
        $this->prunnerApiService->method('loadPipelinesAndJobs')
            ->willReturn($this->jobsResponse('do_quick_content_release', $started));
        $this->prunnerApiService->expects(self::never())->method('schedulePipeline');

        $this->expectException(QuickContentReleaseNotPossibleException::class);
        $this->buildContentReleaseManager()->startQuickContentRelease($this->nodeIdentifiers());
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function quickReleasesOnTheirWay(): array
    {
        return ['running' => [true], 'waiting in the queue' => [false]];
    }

    public function testARunningQuickReleaseIsCancelledAlongWithTheOtherContentReleases(): void
    {
        // it ends up being switched live just like a full release does, so "cancel" has to reach it
        $this->prunnerApiService->method('loadPipelinesAndJobs')
            ->willReturn($this->jobsResponse('do_quick_content_release', true));
        $this->prunnerApiService->expects(self::once())->method('cancelJob');

        $this->buildContentReleaseManager()->cancelAllRunningContentReleases();
    }

    private function nodeIdentifiers(): NodeIdentifiers
    {
        return NodeIdentifiers::fromCommaSeparatedString(self::NODE_IDENTIFIER);
    }

    private function jobsResponse(string $pipeline, bool $started): PipelinesAndJobsResponse
    {
        return PipelinesAndJobsResponse::fromJsonArray([
            'pipelines' => [],
            'jobs' => [
                [
                    'id' => 'job-id',
                    'pipeline' => $pipeline,
                    'tasks' => [],
                    'completed' => false,
                    'canceled' => false,
                    'errored' => false,
                    'created' => '2026-08-17T10:00:00+02:00',
                    'start' => $started ? '2026-08-17T10:00:01+02:00' : null,
                    'user' => 'test',
                ],
            ],
        ]);
    }

    private function buildContentReleaseManager(): ContentReleaseManager
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('get')->willReturn($this->currentContentReleaseId);

        $redisClientManager = $this->createMock(RedisClientManager::class);
        $redisClientManager->method('getPrimaryRedis')->willReturn($redis);

        $securityContext = $this->createMock(Context::class);
        $securityContext->method('isInitialized')->willReturn(false);

        $contentReleaseManager = new ContentReleaseManager();
        $this->inject($contentReleaseManager, 'prunnerApiService', $this->prunnerApiService);
        $this->inject($contentReleaseManager, 'automaticReleaseSwitchService', $this->automaticReleaseSwitchService);
        $this->inject($contentReleaseManager, 'redisClientManager', $redisClientManager);
        $this->inject($contentReleaseManager, 'securityContext', $securityContext);
        $this->inject($contentReleaseManager, 'logger', new NullLogger());

        return $contentReleaseManager;
    }
}
