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

    /**
     * HINCRBY creates the hash it counts in, so counting has to be conditional on the switch still being set: a
     * resume between the caller's isPaused() check and the count would otherwise leave a key behind which holds
     * nothing but a counter. That key lives outside the per-release key space, so pruning never clears it.
     */
    private const COUNT_SUPPRESSED_RELEASE_LUA_SCRIPT = '
        local pauseStateKey = KEYS[1]

        if redis.call("HEXISTS", pauseStateKey, "pausedAt") == 1 then
            redis.call("HINCRBY", pauseStateKey, "suppressedReleaseCount", 1)
        end
    ';

    #[Flow\Inject]
    protected RedisClientManager $redisClientManager;

    #[Flow\Inject]
    protected Context $securityContext;

    public function isPaused(): bool
    {
        // the "pausedAt" field rather than the key itself, because that field is what records the pause - the key
        // holds the counter of what the pause suppressed beside it
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
            'pausedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
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
        $this->redisClientManager->getPrimaryRedis()->eval(
            self::COUNT_SUPPRESSED_RELEASE_LUA_SCRIPT,
            [self::REDIS_KEY],
            1
        );
    }

    private function getAccountId(): ?string
    {
        // getAccount() is documented as always returning an Account, but returns NULL whenever nothing is
        // authenticated - which is the normal case for the CLI triggers
        $account = $this->securityContext->isInitialized() ? $this->securityContext->getAccount() : null;

        return $account?->getAccountIdentifier();
    }
}
