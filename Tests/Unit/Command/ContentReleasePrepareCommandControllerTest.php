<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Command;

use Flowpack\DecoupledContentStore\Command\ContentReleasePrepareCommandController;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class ContentReleasePrepareCommandControllerTest extends UnitTestCase
{
    public function testTheJobWhichBuiltTheReleaseMarksItFailed(): void
    {
        $redisContentReleaseService = $this->serviceHoldingReleaseBuiltBy('build-job');
        $redisContentReleaseService->expects(self::once())
            ->method('setContentReleaseMetadata')
            ->with(
                self::anything(),
                self::callback(static fn(ContentReleaseMetadata $metadata): bool => $metadata->getStatus()->isFailed()),
                self::anything(),
            );

        $this->controllerWith($redisContentReleaseService)->markFailedCommand('80', 'build-job');
    }

    public function testAnotherPipelineSharingTheHookLeavesTheReleaseAlone(): void
    {
        // e.g. a failed manual transfer of a release which is fine
        $redisContentReleaseService = $this->serviceHoldingReleaseBuiltBy('build-job');
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

    private function serviceHoldingReleaseBuiltBy(string $prunnerJobId): RedisContentReleaseService&MockObject
    {
        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $redisContentReleaseService->method('fetchMetadataForContentRelease')
            ->willReturn(ContentReleaseMetadata::create(
                PrunnerJobId::fromString($prunnerJobId),
                new \DateTimeImmutable(),
            ));
        return $redisContentReleaseService;
    }

    private function controllerWith(
        RedisContentReleaseService $redisContentReleaseService,
    ): ContentReleasePrepareCommandController {
        $controller = new ContentReleasePrepareCommandController();
        $this->inject($controller, 'redisContentReleaseService', $redisContentReleaseService);
        return $controller;
    }
}
