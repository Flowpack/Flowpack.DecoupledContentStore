<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Core;

use DateTimeImmutable;
use DateTimeInterface;
use Flowpack\DecoupledContentStore\Core\AutomaticReleaseSwitchService;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Neos\Flow\Security\Context;
use Neos\Flow\Tests\UnitTestCase;
use Redis;

/**
 * Tests the switch which suppresses automatically triggered content releases.
 *
 * The state lives in a single Redis hash, so the interesting behaviour is which field decides that the switch is
 * set, and that pausing twice does not wipe what the first pause recorded.
 */
final class AutomaticReleaseSwitchServiceTest extends UnitTestCase
{
    private const REDIS_KEY = 'contentStore:automaticReleasesPaused';

    public function testTheSwitchIsSetOnlyIfThePausedAtFieldExists(): void
    {
        // countSuppressedRelease() re-creates the key with nothing but its counter if it races a resume, so the
        // existence of the key itself says nothing
        $redis = $this->createMock(Redis::class);
        $redis->method('hExists')->with(self::REDIS_KEY, 'pausedAt')->willReturn(false);

        self::assertFalse($this->buildService($redis)->isPaused());
    }

    public function testPausingWhileAlreadyPausedKeepsTheOriginalState(): void
    {
        // otherwise the second pause would reset both the timestamp and the count of suppressed releases
        $redis = $this->createMock(Redis::class);
        $redis->method('hExists')->willReturn(true);
        $redis->expects(self::never())->method('hMSet');

        $this->buildService($redis)->pause();
    }

    public function testPausingRecordsTheTimestampAndAnEmptyCounter(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('hExists')->willReturn(false);
        $redis
            ->expects(self::once())
            ->method('hMSet')
            ->with(self::REDIS_KEY, self::callback(static function (array $hash): bool {
                return (
                    $hash['accountId'] === ''
                    && $hash['suppressedReleaseCount'] === 0
                    && DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $hash['pausedAt']) !== false
                );
            }));

        $this->buildService($redis)->pause();
    }

    public function testThereIsNoPauseStateWhileTheSwitchIsNotSet(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('hGetAll')->willReturn([]);

        self::assertNull($this->buildService($redis)->getPauseState());
    }

    public function testThePauseStateIsReadFromTheHash(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis
            ->method('hGetAll')
            ->willReturn([
                'pausedAt' => '2026-08-13T09:15:00+02:00',
                'accountId' => 'admin',
                'suppressedReleaseCount' => '4'
            ]);

        $pauseState = $this->buildService($redis)->getPauseState();

        self::assertNotNull($pauseState);
        self::assertSame('admin', $pauseState->getAccountId());
        self::assertSame(4, $pauseState->getSuppressedReleaseCount());
    }

    private function buildService(Redis $redis): AutomaticReleaseSwitchService
    {
        $redisClientManager = $this->createMock(RedisClientManager::class);
        $redisClientManager->method('getPrimaryRedis')->willReturn($redis);

        $securityContext = $this->createMock(Context::class);
        $securityContext->method('isInitialized')->willReturn(false);

        $service = new AutomaticReleaseSwitchService();
        $this->inject($service, 'redisClientManager', $redisClientManager);
        $this->inject($service, 'securityContext', $securityContext);

        return $service;
    }
}
