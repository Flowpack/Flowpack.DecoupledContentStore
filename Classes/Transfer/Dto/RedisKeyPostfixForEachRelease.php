<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class RedisKeyPostfixForEachRelease
{

    private const TRANSFER_MODE_HASH_INCREMENTAL = 'hash_incremental';
    private const TRANSFER_MODE_DUMP = 'dump';

    protected string $redisKeyPostfix;
    protected bool $transfer;
    protected string $transferMode;
    protected bool $isRequired;

    /**
     * @param string $redisKeyPostfix
     * @param bool $transfer
     * @param string $transferMode
     * @param bool $isRequired
     */
    private function __construct(string $redisKeyPostfix, bool $transfer, string $transferMode, bool $isRequired)
    {
        if (!in_array($transferMode, [self::TRANSFER_MODE_HASH_INCREMENTAL, self::TRANSFER_MODE_DUMP])) {
            throw new \RuntimeException('TransferMode ' . $transferMode . ' not supported.');
        }

        $this->redisKeyPostfix = $redisKeyPostfix;
        $this->transfer = $transfer;
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
    public function shouldTransfer(): bool
    {
        return $this->transfer;
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
