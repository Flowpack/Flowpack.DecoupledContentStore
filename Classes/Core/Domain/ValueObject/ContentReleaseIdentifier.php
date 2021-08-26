<?php

namespace Flowpack\DecoupledContentStore\Core\Domain\ValueObject;

use Flowpack\DecoupledContentStore\Exception;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class ContentReleaseIdentifier implements \JsonSerializable
{

    /**
     * @var string
     */
    private $identifier;

    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    private function __construct(string $identifier)
    {
        if (!preg_match('/^[0-9]+$/', $identifier)) {
            throw new Exception('Content release identifier malformed; must be numeric only. Given: ' . $identifier);
        }
        $this->identifier = $identifier;
    }

    public static function fromString(string $identifier): self
    {
        return new self($identifier);
    }

    public static function create(): self
    {
        return new self("" . time());
    }

    public function redisKey(string $postfix): string
    {
        return 'contentStore:' . $this->identifier . ':' . $postfix;
    }

    private function validateAgainstSettings(string $identifier): bool
    {
        $keyMatchesSettings = false;

        $redisKeyPostfixesForEachRelease = RedisKeyPostfixesForEachRelease::fromArray($this->redisKeyPostfixesForEachReleaseConfiguration);

        foreach ($redisKeyPostfixesForEachRelease->getRedisKeyPostfixes() as $redisKeyPostfix) {
            if ($redisKeyPostfix->getRedisKeyPostfix() === $identifier) {
                $keyMatchesSettings = true;
            }
        }

        return $keyMatchesSettings;
    }

    public function jsonSerialize(): string
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
