<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class NodeRenderingCompletionStatus implements \JsonSerializable
{

    private const SCHEDULED = 'scheduled';
    private const RUNNING = 'running';
    private const SUCCESS = 'success';
    private const FAILED = 'failed';

    /**
     * @var string
     */
    private $status;

    private function __construct(string $status)
    {
        if (!in_array($status, [self::SCHEDULED, self::RUNNING, self::SUCCESS, self::FAILED])) {
            throw new \InvalidArgumentException('NodeRenderingCompletionStatus "' . $status . '" not found.');
        }
        $this->status = $status;
    }

    public static function fromString(string $status): self
    {
        return new self($status);
    }

    public static function scheduled(): self
    {
        return new self(self::SCHEDULED);
    }

    public static function running(): self
    {
        return new self(self::RUNNING);
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

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDisplayName(): string
    {
        return $this->status;
    }

    public function hasCompleted(): bool
    {
        return $this->isSuccessful() || $this->isFailed();
    }

    public function jsonSerialize()
    {
        return $this->status;
    }

}
