<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Core;

use DateTimeImmutable;
use DateTimeInterface;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\AutomaticReleasePauseState;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;

/**
 * Reads and writes the switch which suppresses automatically triggered content releases (workspace publish, asset
 * change, re-release after a rendering error).
 *
 * While the switch is set, an editor's publish does not go live. It is therefore only ever cleared by an explicit
 * resume, never by a finished release, and it lives outside the per-release key space so that pruning does not
 * remove it.
 */
#[Flow\Scope('singleton')]
class AutomaticReleaseSwitchService
{
    private const REDIS_KEY = 'contentStore:automaticReleasesPaused';

    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected Context $securityContext;

    public function isPaused(): bool
    {
        // the "pausedAt" field, not the key itself: countSuppressedRelease() can re-create the key with only its
        // counter field if a resume happens in between.
        return (bool) $this->redisClientManager->getPrimaryRedis()->hExists(self::REDIS_KEY, 'pausedAt');
    }

    public function getPauseState(): ?AutomaticReleasePauseState
    {
        $redisHash = $this->redisClientManager->getPrimaryRedis()->hGetAll(self::REDIS_KEY);
        if (!array_key_exists('pausedAt', $redisHash)) {
            return null;
        }

        return AutomaticReleasePauseState::fromRedisHash($redisHash);
    }

    public function pause(): void
    {
        if ($this->isPaused()) {
            return;
        }

        $this->redisClientManager->getPrimaryRedis()->hMset(self::REDIS_KEY, [
            'pausedAt' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'accountId' => $this->getAccountId() ?? '',
            'suppressedReleaseCount' => 0
        ]);
    }

    public function resume(): void
    {
        $this->redisClientManager->getPrimaryRedis()->del(self::REDIS_KEY);
    }

    public function countSuppressedRelease(): void
    {
        $this->redisClientManager->getPrimaryRedis()->hIncrBy(self::REDIS_KEY, 'suppressedReleaseCount', 1);
    }

    private function getAccountId(): ?string
    {
        // getAccount() is documented as always returning an Account, but returns NULL whenever nothing is
        // authenticated - which is the normal case for the CLI triggers
        $account = $this->securityContext->isInitialized() ? $this->securityContext->getAccount() : null;

        return $account?->getAccountIdentifier();
    }
}
