<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Transfer\Dto;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;

class RedisKeyPostfixesForEachRelease
{

    /**
     * @var RedisKeyPostfixForEachRelease[]
     */
    protected array $redisKeyPostfixes;

    /**
     * @param RedisKeyPostfixForEachRelease[] $redisKeyPostfixes
     */
    private function __construct(array $redisKeyPostfixes)
    {
        foreach ($redisKeyPostfixes as $element) {
            assert($element instanceof RedisKeyPostfixForEachRelease);
        }
        $this->redisKeyPostfixes = $redisKeyPostfixes;
    }


    public static function fromArray(array $in): self
    {
        $result = [];
        foreach ($in as $config) {
            if (is_array($config)) {
                $result[] = RedisKeyPostfixForEachRelease::fromArray($config);
            }
        }
        return new self($result);
    }

    /**
     * @return iterable|RedisKeyPostfixForEachRelease[]
     */
    public function getKeysToTransfer(RedisInstanceIdentifier $redisInstanceIdentifier): iterable
    {
        foreach ($this->redisKeyPostfixes as $redisKeyPostfix) {
            if ($redisKeyPostfix->shouldTransfer($redisInstanceIdentifier)) {
                yield $redisKeyPostfix;
            }
        }
    }

    /**
     * @return iterable|RedisKeyPostfixForEachRelease[]
     */
    public function getRequiredKeys(): iterable
    {
        foreach ($this->redisKeyPostfixes as $redisKeyPostfix) {
            if ($redisKeyPostfix->isRequired()) {
                yield $redisKeyPostfix;
            }
        }
    }

    /**
     * @return RedisKeyPostfixForEachRelease[]
     */
    public function getRedisKeyPostfixes(): array
    {
        return $this->redisKeyPostfixes;
    }
}
