<?php

namespace Flowpack\DecoupledContentStore\Core\Domain\ValueObject;

use Flowpack\Prunner\ValueObject\JobId;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class PrunnerJobId implements \JsonSerializable
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

    public function toJobId(): JobId
    {
        return JobId::create($this->identifier);
    }
}