<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Transfer;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Flowpack\DecoupledContentStore\Transfer\ContentReleaseCleaner;
use Neos\Flow\Tests\UnitTestCase;
use Redis;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests which keys of a removed release are deleted right away, and which ones Redis expires later.
 */
final class ContentReleaseCleanerTest extends UnitTestCase
{
    /**
     * @var string[]
     */
    private array $deletedKeys = [];

    /**
     * @var array<string, int>
     */
    private array $expiringKeys = [];

    public function testTheFlaggedKeysExpireInsteadOfBeingDeleted(): void
    {
        $this->removeRelease(432_000);

        self::assertSame(['contentStore:5:data'], $this->deletedKeys);
        self::assertSame(['contentStore:5:meta:info' => 432_000], $this->expiringKeys);
    }

    public function testEverythingIsDeletedOnAContentStoreWithoutRetention(): void
    {
        $this->removeRelease(0);

        self::assertSame(['contentStore:5:data', 'contentStore:5:meta:info'], $this->deletedKeys);
        self::assertSame([], $this->expiringKeys);
    }

    private function removeRelease(int $retentionSeconds): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('del')
            ->willReturnCallback(function (string $key): int {
                $this->deletedKeys[] = $key;
                return 1;
            });
        $redis->method('expire')
            ->willReturnCallback(function (string $key, int $seconds): bool {
                $this->expiringKeys[$key] = $seconds;
                return true;
            });
        $redis->expects(self::once())->method('zRem')->with('contentStore:registeredReleases', '5');

        $redisClientManager = $this->createMock(RedisClientManager::class);
        $redisClientManager->method('getRedis')->willReturn($redis);
        $redisClientManager->method('getRemovedReleaseRetentionSeconds')->willReturn($retentionSeconds);

        $redisReleaseSwitchService = $this->createMock(RedisReleaseSwitchService::class);
        $redisReleaseSwitchService->method('getCurrentRelease')->willReturn(ContentReleaseIdentifier::fromString('6'));

        $redisKeyService = new RedisKeyService();
        $this->inject($redisKeyService, 'redisKeyPostfixesForEachReleaseConfiguration', self::keyConfiguration());

        $cleaner = new ContentReleaseCleaner();
        $this->inject($cleaner, 'redisClientManager', $redisClientManager);
        $this->inject($cleaner, 'redisKeyService', $redisKeyService);
        $this->inject($cleaner, 'redisReleaseSwitchService', $redisReleaseSwitchService);
        $this->inject($cleaner, 'redisKeyPostfixesForEachReleaseConfiguration', self::keyConfiguration());

        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString('5');
        $cleaner->removeRelease(
            $contentReleaseIdentifier,
            RedisInstanceIdentifier::primary(),
            ContentReleaseLogger::fromSymfonyOutput(new BufferedOutput(), $contentReleaseIdentifier),
        );
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
            ],
            'metainfo' => [
                'redisKeyPostfix' => 'meta:info',
                'transfer' => true,
                'transferMode' => 'dump',
                'isRequired' => true,
                'keepAfterRemoval' => true,
            ],
        ];
    }
}
