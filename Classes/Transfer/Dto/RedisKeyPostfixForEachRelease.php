<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer\Dto;

use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Exception\InvalidTransferConfigException;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class RedisKeyPostfixForEachRelease
{
    private const TRANSFER_MODE_HASH_INCREMENTAL = 'hash_incremental';
    private const TRANSFER_MODE_DUMP = 'dump';

    protected string $redisKeyPostfix;
    protected array $transfer;
    protected string $transferMode;
    protected bool $isRequired;
    protected bool $copyOnQuickRelease;

    /**
     * @param string $redisKeyPostfix
     * @param bool|array $transfer
     * @param string $transferMode
     * @param bool $isRequired
     * @param bool $copyOnQuickRelease
     */
    private function __construct(
        string $redisKeyPostfix,
        $transfer,
        string $transferMode,
        bool $isRequired,
        bool $copyOnQuickRelease
    ) {
        if (!in_array($transferMode, [self::TRANSFER_MODE_HASH_INCREMENTAL, self::TRANSFER_MODE_DUMP])) {
            throw new \RuntimeException('TransferMode ' . $transferMode . ' not supported.');
        }

        if (is_bool($transfer)) {
            $this->transfer = [
                '*' => $transfer
            ];
        } else {
            $this->transfer = $transfer;
        }

        $this->redisKeyPostfix = $redisKeyPostfix;
        $this->transferMode = $transferMode;
        $this->isRequired = $isRequired;
        $this->copyOnQuickRelease = $copyOnQuickRelease;
    }

    public static function fromArray(array $in): self
    {
        return new self(
            $in['redisKeyPostfix'],
            $in['transfer'],
            $in['transferMode'],
            $in['isRequired'],
            // keys registered before quick releases existed do not carry the flag, and not copying them is the safe
            // default: a key which should have been copied shows up as missing content, a key which should not have
            // been copied describes a different release
            $in['copyOnQuickRelease'] ?? false
        );
    }

    /**
     * @return bool
     */
    public function shouldTransfer(RedisInstanceIdentifier $redisInstanceIdentifier): bool
    {
        if (array_key_exists($redisInstanceIdentifier->getIdentifier(), $this->transfer)) {
            return $this->transfer[$redisInstanceIdentifier->getIdentifier()];
        }
        if (array_key_exists('*', $this->transfer)) {
            return $this->transfer['*'];
        }
        throw new InvalidTransferConfigException('No valid transfer mode is configured.');
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function shouldCopyOnQuickRelease(): bool
    {
        return $this->copyOnQuickRelease;
    }

    public function getRedisKeyPostfix(): string
    {
        return $this->redisKeyPostfix;
    }

    /**
     * @return string
     */
    public function getTransferMode(): string
    {
        return $this->transferMode;
    }

    public function hasTransferModeHashIncremental(): bool
    {
        return $this->transferMode === self::TRANSFER_MODE_HASH_INCREMENTAL;
    }
}
