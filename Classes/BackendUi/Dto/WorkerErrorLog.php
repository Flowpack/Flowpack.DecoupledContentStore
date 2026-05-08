<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi\Dto;

use Flowpack\DecoupledContentStore\BackendUi\WorkerErrorLogAggregator;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class WorkerErrorLog
{
    public bool $wasKilledByOrchestrator;

    /**
     * @param string[] $errorBlocks
     */
    public function __construct(
        public string $workerName,
        public string $status,
        public int $exitCode,
        public ?string $taskError,
        public array $errorBlocks,
        public ?string $lastAttemptedNode = null
    ) {
        $this->wasKilledByOrchestrator = $this->exitCode === WorkerErrorLogAggregator::EXIT_CODE_SIGTERM;
    }
}
