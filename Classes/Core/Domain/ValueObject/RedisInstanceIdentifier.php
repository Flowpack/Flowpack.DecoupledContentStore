<?php

namespace Flowpack\DecoupledContentStore\Core\Domain\ValueObject;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class RedisInstanceIdentifier
{

    // must match the default config in Settings.yaml
    private const PRIMARY = 'primary';

    /**
     * @var string
     */
    private $identifier;

    private function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }

    public static function primary(): self
    {
        return new self(self::PRIMARY);
    }

    public static function fromString(string $identifier): self
    {
        return new self($identifier);
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}