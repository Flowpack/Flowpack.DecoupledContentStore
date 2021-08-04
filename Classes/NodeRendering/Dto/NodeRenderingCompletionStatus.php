<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class NodeRenderingCompletionStatus implements \JsonSerializable
{

    private const SUCCESS = 'success';
    private const FAILED = 'failed';

    /**
     * @var string
     */
    private $status;

    private function __construct(string $status)
    {
        if (!in_array($status, [self::SUCCESS, self::FAILED])) {
            throw new \InvalidArgumentException('NodeRenderingCompletionStatus "' . $status . '" not found.');
        }
        $this->status = $status;
    }

    public static function fromString(string $status): self
    {
        return new self($status);
    }

    public static function success(): self
    {
        return new self(self::SUCCESS);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function fromJsonString($jsonString): self
    {
        return new self(json_decode($jsonString, true));
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }


    public function jsonSerialize()
    {
        return $this->status;
    }
}