<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\QuickPublish\Infrastructure;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\QuickPublish\Infrastructure\RedisReleaseCopyService;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the copy-forward a quick content release is built on.
 *
 * What matters here is what is refused: everything the copy does not overwrite is inherited from the source release
 * and published as if it had been rendered.
 */
final class RedisReleaseCopyServiceTest extends UnitTestCase
{
    private const SOURCE_KEYS = [
        'contentStore:5:data',
        'contentStore:5:meta:urls',
        'contentStore:5:renderingJobQueue',
    ];

    /**
     * @var array<array{string, string}>
     */
    private array $copiedKeys = [];

    public function testOnlyTheFlaggedKeysAreCopied(): void
    {
        $this->copyRelease($this->buildRedis(), $this->buildRedisContentReleaseService());

        // renderingJobQueue exists on the source, but describes the build of that release rather than its content
        self::assertSame(
            [
                ['contentStore:5:data',      'contentStore:6:data'],
                ['contentStore:5:meta:urls', 'contentStore:6:meta:urls'],
            ],
            $this->copiedKeys,
        );
    }

    public function testAKeyWhichDoesNotExistOnTheSourceIsSkipped(): void
    {
        $redis = $this->buildRedis(['contentStore:5:data', 'contentStore:5:meta:urls']);

        $this->copyRelease($redis, $this->buildRedisContentReleaseService());

        self::assertCount(2, $this->copiedKeys);
    }

    public function testCopyingIsRefusedOnAServerWithoutTheCopyCommand(): void
    {
        $redis = $this->buildRedis(self::SOURCE_KEYS, '6.0.20');

        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786953587);

        $this->copyRelease($redis, $this->buildRedisContentReleaseService());
    }

    public function testAReleaseWhichDidNotFinishIsNotCopied(): void
    {
        // the source release is the one which is currently live, and an administrator can switch to any release
        $redisContentReleaseService = $this->buildRedisContentReleaseService(NodeRenderingCompletionStatus::running());

        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786953589);

        $this->copyRelease($this->buildRedis(), $redisContentReleaseService);
    }

    public function testAReleaseWhichDoesNotExistIsNotCopied(): void
    {
        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $redisContentReleaseService->method('fetchMetadataForContentRelease')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786953588);

        $this->copyRelease($this->buildRedis(), $redisContentReleaseService);
    }

    public function testAReleaseMissingOneOfItsRequiredKeysIsNotCopied(): void
    {
        $redis = $this->buildRedis(['contentStore:5:data']);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786953590);

        $this->copyRelease($redis, $this->buildRedisContentReleaseService());
    }

    public function testARequiredKeyWhichIsNotCopiedMayBeMissingOnTheSource(): void
    {
        // enumeration:documentNodes is required, but a quick release enumerates the given nodes itself - and a key
        // an installation does not write at all is registered as required as well
        $this->copyRelease($this->buildRedis(), $this->buildRedisContentReleaseService());

        self::assertCount(2, $this->copiedKeys);
    }

    public function testAReleaseIsNotCopiedOntoItself(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1786953585);

        $this->copyRelease($this->buildRedis(), $this->buildRedisContentReleaseService(), '5');
    }

    private function copyRelease(
        \Redis $redis,
        RedisContentReleaseService $redisContentReleaseService,
        string $targetContentReleaseIdentifier = '6',
    ): void {
        $redisClientManager = $this->createMock(RedisClientManager::class);
        $redisClientManager->method('getRedis')->willReturn($redis);

        $redisKeyService = new RedisKeyService();
        $this->inject($redisKeyService, 'redisKeyPostfixesForEachReleaseConfiguration', self::keyConfiguration());

        $service = new RedisReleaseCopyService();
        $this->inject($service, 'redisClientManager', $redisClientManager);
        $this->inject($service, 'redisKeyService', $redisKeyService);
        $this->inject($service, 'redisContentReleaseService', $redisContentReleaseService);
        $this->inject($service, 'redisKeyPostfixesForEachReleaseConfiguration', self::keyConfiguration());

        $service->copyReleaseWithin(
            RedisInstanceIdentifier::primary(),
            ContentReleaseIdentifier::fromString('5'),
            ContentReleaseIdentifier::fromString($targetContentReleaseIdentifier),
            ContentReleaseLogger::fromSymfonyOutput(new BufferedOutput(), ContentReleaseIdentifier::fromString('6')),
        );
    }

    /**
     * @param array<string> $existingKeys
     * @return \Redis&MockObject
     */
    private function buildRedis(array $existingKeys = self::SOURCE_KEYS, string $redisVersion = '7.2.4'): \Redis
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('info')->willReturn(['redis_version' => $redisVersion]);
        $redis->method('exists')->willReturnCallback(static fn(string $key): int => in_array($key, $existingKeys, true)
            ? 1
            : 0);
        $redis->method('copy')
            ->willReturnCallback(function (string $sourceKey, string $targetKey): bool {
                $this->copiedKeys[] = [$sourceKey, $targetKey];
                return true;
            });

        return $redis;
    }

    /**
     * @return RedisContentReleaseService&MockObject
     */
    private function buildRedisContentReleaseService(
        ?NodeRenderingCompletionStatus $status = null,
    ): RedisContentReleaseService {
        $metadata = ContentReleaseMetadata::create(
            PrunnerJobId::fromString('job'),
            new \DateTimeImmutable(),
        )->withStatus($status ?? NodeRenderingCompletionStatus::success());

        $redisContentReleaseService = $this->createMock(RedisContentReleaseService::class);
        $redisContentReleaseService->method('fetchMetadataForContentRelease')->willReturn($metadata);

        return $redisContentReleaseService;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function keyConfiguration(): array
    {
        return [
            'data' => [
                'redisKeyPostfix' => 'data',
                'transfer' => true,
                'transferMode' => 'hash_incremental',
                'isRequired' => true,
                'copyOnQuickRelease' => true,
            ],
            'metaUrls' => [
                'redisKeyPostfix' => 'meta:urls',
                'transfer' => true,
                'transferMode' => 'dump',
                'isRequired' => true,
                'copyOnQuickRelease' => true,
            ],
            'renderingJobQueue' => [
                'redisKeyPostfix' => 'renderingJobQueue',
                'transfer' => false,
                'transferMode' => 'dump',
                'isRequired' => false,
                'copyOnQuickRelease' => false,
            ],
            'enumerationDocumentNodes' => [
                'redisKeyPostfix' => 'enumeration:documentNodes',
                'transfer' => true,
                'transferMode' => 'dump',
                'isRequired' => true,
                'copyOnQuickRelease' => false,
            ],
        ];
    }
}
