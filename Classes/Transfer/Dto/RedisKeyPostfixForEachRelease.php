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

    /**
     * @param string $redisKeyPostfix
     * @param bool|array $transfer
     * @param string $transferMode
     * @param bool $isRequired
     */
    private function __construct(string $redisKeyPostfix, $transfer, string $transferMode, bool $isRequired)
    {
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
    }


    public static function fromArray(array $in): self
    {
        return new self(
            $in['redisKeyPostfix'],
            $in['transfer'],
            $in['transferMode'],
            $in['isRequired']
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
