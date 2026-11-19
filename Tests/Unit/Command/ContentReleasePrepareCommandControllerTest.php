<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Command;

use DateTimeImmutable;
use Flowpack\DecoupledContentStore\Command\ContentReleasePrepareCommandController;
use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Fusion\Core\Cache\ContentCache;
use PHPUnit\Framework\MockObject\MockObject;

final class ContentReleasePrepareCommandControllerTest extends UnitTestCase
{
    public function testTheJobWhichBuiltTheReleaseMarksItFailed(): void
    {
        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $this->holdReleaseBuiltBy($redisContentReleaseService, 'build-job');
        $redisContentReleaseService
            ->expects(self::once())
            ->method('setContentReleaseMetadata')
            ->with(
                self::anything(),
                self::callback(static fn(ContentReleaseMetadata $metadata): bool => $metadata->status->isFailed()),
                self::anything(),
            );

        $this->controllerWith($redisContentReleaseService)->markFailedCommand('80', 'build-job');
    }

    public function testAnotherPipelineSharingTheHookLeavesTheReleaseAlone(): void
    {
        // e.g. a failed manual transfer of a release which is fine
        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $this->holdReleaseBuiltBy($redisContentReleaseService, 'build-job');
        $redisContentReleaseService->expects(self::never())->method('setContentReleaseMetadata');

        $this->controllerWith($redisContentReleaseService)->markFailedCommand('80', 'transfer-job');
    }

    public function testAReleaseWhichIsGoneIsIgnored(): void
    {
        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $redisContentReleaseService->method('fetchMetadataForContentRelease')->willReturn(null);
        $redisContentReleaseService->expects(self::never())->method('setContentReleaseMetadata');

        $this->controllerWith($redisContentReleaseService)->markFailedCommand('80', 'build-job');
    }

    /**
     * takes the mock instead of creating it: PHPStan cannot know about dg/bypass-finals, so it has no type for a mock
     * of the final RedisContentReleaseService which a method could return
     */
    private function holdReleaseBuiltBy(MockObject $redisContentReleaseService, string $prunnerJobId): void
    {
        $redisContentReleaseService
            ->method('fetchMetadataForContentRelease')
            ->willReturn(ContentReleaseMetadata::create(
                PrunnerJobId::fromString($prunnerJobId),
                new DateTimeImmutable(),
            ));
    }

    private function controllerWith(
        RedisContentReleaseService $redisContentReleaseService,
    ): ContentReleasePrepareCommandController {
        return new ContentReleasePrepareCommandController(
            $redisContentReleaseService,
            $this->createMock(ConcurrentBuildLockService::class),
            $this->createMock(ContentCache::class),
        );
    }
}
