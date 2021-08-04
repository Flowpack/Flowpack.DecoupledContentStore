<?php

namespace Flowpack\DecoupledContentStore\Core\Domain\ValueObject;

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

    private function __construct(string $identifier)
    {
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
        return 'cs:' . $this->identifier . ':' . $postfix;
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