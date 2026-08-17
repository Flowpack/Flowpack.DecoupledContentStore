<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Tests\Unit\Core\Domain\ValueObject;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\AutomaticReleasePauseState;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Tests the mapping of the "contentStore:automaticReleasesPaused" Redis hash onto the pause state shown in the
 * backend module. Everything arrives as a string, and only "pausedAt" is guaranteed to be there.
 */
final class AutomaticReleasePauseStateTest extends UnitTestCase
{
    public function testAllFieldsAreReadFromTheHash(): void
    {
        $pauseState = AutomaticReleasePauseState::fromRedisHash([
            'pausedAt' => '2026-08-13T09:15:00+02:00',
            'accountId' => 'admin',
            'suppressedReleaseCount' => '7'
        ]);

        self::assertSame('2026-08-13T09:15:00+02:00', $pauseState->getPausedAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('admin', $pauseState->getAccountId());
        self::assertSame(7, $pauseState->getSuppressedReleaseCount());
    }

    public function testAnEmptyAccountIdBecomesNull(): void
    {
        // pause() writes an empty string when no account could be determined, because a Redis hash has no null
        $pauseState = AutomaticReleasePauseState::fromRedisHash([
            'pausedAt' => '2026-08-13T09:15:00+02:00',
            'accountId' => '',
            'suppressedReleaseCount' => '0'
        ]);

        self::assertNull($pauseState->getAccountId());
    }

    public function testTheCounterDefaultsToZero(): void
    {
        $pauseState = AutomaticReleasePauseState::fromRedisHash([
            'pausedAt' => '2026-08-13T09:15:00+02:00'
        ]);

        self::assertSame(0, $pauseState->getSuppressedReleaseCount());
        self::assertNull($pauseState->getAccountId());
    }

    public function testAHashWithoutPausedAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AutomaticReleasePauseState::fromRedisHash(['suppressedReleaseCount' => '3']);
    }
}
