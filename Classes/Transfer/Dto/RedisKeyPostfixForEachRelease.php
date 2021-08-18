<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer\Dto;
class RedisKeyPostfixForEachRelease
{

    protected string $redisKeyPostfix;

    protected bool $enabled;

    private const TRANSFER_MODE_HASH_INCREMENTAL = 'hash_incremental';
    private const TRANSFER_MODE_DUMP = 'dump';
    protected string $transferMode;

    protected bool $isRequired;

    /**
     * @param string $redisKeyPostfix
     * @param bool $enabled
     * @param string $transferMode
     * @param bool $isRequired
     */
    private function __construct(string $redisKeyPostfix, bool $enabled, string $transferMode, bool $isRequired)
    {
        if (!in_array($transferMode, [self::TRANSFER_MODE_HASH_INCREMENTAL, self::TRANSFER_MODE_DUMP])) {
            throw new \RuntimeException('TransferMode ' . $transferMode . ' not supported.');
        }

        $this->redisKeyPostfix = $redisKeyPostfix;
        $this->enabled = $enabled;
        $this->transferMode = $transferMode;
        $this->isRequired = $isRequired;
    }


    public static function fromArray(string $key, array $in): self
    {
        return new self(
            $key,
            $in['enabled'],
            $in['transferMode'],
            $in['isRequired']
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function getRedisKeyPostfix(): string
    {
        return $this->redisKeyPostfix;
    }


}