<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core\Domain\ValueObject;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;

/**
 * State of the switch which suppresses automatically triggered content releases.
 */
#[Flow\Proxy(false)]
final class AutomaticReleasePauseState
{
    private DateTimeImmutable $pausedAt;

    private ?string $accountId;

    private int $suppressedReleaseCount;

    private function __construct(DateTimeImmutable $pausedAt, ?string $accountId, int $suppressedReleaseCount)
    {
        $this->pausedAt = $pausedAt;
        $this->accountId = $accountId;
        $this->suppressedReleaseCount = $suppressedReleaseCount;
    }

    /**
     * "pausedAt" is what marks the switch as set, so a hash without it is not a pause state - see
     * AutomaticReleaseSwitchService::isPaused(). Rejected rather than defaulted, because inventing a timestamp would
     * hide the inconsistency behind a banner claiming the pause started just now.
     *
     * @param array<string, string> $redisHash
     * @throws DateMalformedStringException
     */
    public static function fromRedisHash(array $redisHash): self
    {
        if (!array_key_exists('pausedAt', $redisHash)) {
            throw new InvalidArgumentException(
                'The automatic release pause state must contain a "pausedAt" field.',
                1786706446
            );
        }

        return new self(
            new DateTimeImmutable($redisHash['pausedAt']),
            ($redisHash['accountId'] ?? '') !== '' ? $redisHash['accountId'] : null,
            (int)($redisHash['suppressedReleaseCount'] ?? 0)
        );
    }

    public function getPausedAt(): DateTimeImmutable
    {
        return $this->pausedAt;
    }

    /**
     * The account which paused the automatic releases, if it could be determined.
     */
    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    /**
     * How many automatically triggered releases have been suppressed since the pause started.
     */
    public function getSuppressedReleaseCount(): int
    {
        return $this->suppressedReleaseCount;
    }
}
