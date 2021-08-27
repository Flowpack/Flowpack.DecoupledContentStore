<?php

namespace Flowpack\DecoupledContentStore\Core\Domain\ValueObject;

use Flowpack\DecoupledContentStore\Exception;
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
