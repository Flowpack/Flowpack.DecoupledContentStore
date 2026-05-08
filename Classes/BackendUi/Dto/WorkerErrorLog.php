<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\BackendUi\WorkerErrorLogAggregator;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class WorkerErrorLog implements ProtectedContextAwareInterface
{
    private string $workerName;
    private string $status;
    private int $exitCode;
    private ?string $taskError;
    private ?string $lastAttemptedNode;

    /**
     * @var string[]
     */
    private array $errorBlocks;

    /**
     * @param string[] $errorBlocks
     */
    public function __construct(
        string $workerName,
        string $status,
        int $exitCode,
        ?string $taskError,
        array $errorBlocks,
        ?string $lastAttemptedNode = null
    ) {
        $this->workerName = $workerName;
        $this->status = $status;
        $this->exitCode = $exitCode;
        $this->taskError = $taskError;
        $this->errorBlocks = $errorBlocks;
        $this->lastAttemptedNode = $lastAttemptedNode;
    }

    public function getWorkerName(): string
    {
        return $this->workerName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getTaskError(): ?string
    {
        return $this->taskError;
    }

    /**
     * @return string[]
     */
    public function getErrorBlocks(): array
    {
        return $this->errorBlocks;
    }

    public function getLastAttemptedNode(): ?string
    {
        return $this->lastAttemptedNode;
    }

    public function wasKilledByOrchestrator(): bool
    {
        return $this->exitCode === WorkerErrorLogAggregator::EXIT_CODE_SIGTERM;
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
