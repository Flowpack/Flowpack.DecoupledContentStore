<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit;

use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\Prunner\PrunnerApiService;
use Flowpack\Prunner\ValueObject\JobId;
use Neos\Flow\Security\Context;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Tests the effect of the pause switch on scheduling.
 *
 * All automatic triggers (workspace publish, asset change, re-release after a rendering error) go through
 * startIncrementalContentRelease(), so that method is the only gate. "Publish All" is an explicit request and must
 * stay unaffected - otherwise the pause could not be used to prepare a release by hand.
 */
class ContentReleaseManagerTest extends UnitTestCase
{
    private PrunnerApiService&MockObject $prunnerApiService;

    private AutomaticReleaseSwitchService&MockObject $automaticReleaseSwitchService;

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

    private function buildContentReleaseManager(): ContentReleaseManager
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('get')->willReturn(false);

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
