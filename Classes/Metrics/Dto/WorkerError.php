<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Metrics\Dto;

use Flowpack\DecoupledContentStore\BackendUi\Dto\WorkerErrorLog;
use Flowpack\DecoupledContentStore\Metrics\NodeIdentifierExtractor;
use Neos\Flow\Annotations as Flow;

/**
 * What one failed render worker left in its prunner log.
 *
 * Those log files live on the container's disk and fall out of the pipeline's `retention_count` quickly, so the
 * stacktrace is copied into the measurement of the release while it is still readable.
 *
 * @Flow\Proxy(false)
 */
final class WorkerError implements \JsonSerializable
{
    /**
     * Stacktraces are long, and a release which broke usually broke in more than one worker. These caps keep one
     * measurement inside the size a log aggregator will still accept.
     */
    private const MAX_ERROR_BLOCKS = 5;
    private const MAX_ERROR_BLOCK_LENGTH = 4000;

    /**
     * @param string[] $errorBlocks
     */
    private function __construct(
        public readonly string $workerName,
        public readonly string $status,
        public readonly int $exitCode,
        public readonly bool $wasKilledByOrchestrator,
        public readonly ?string $taskError,
        public readonly ?string $lastAttemptedNode,
        public readonly ?string $lastAttemptedNodeIdentifier,
        public readonly array $errorBlocks,
    ) {
    }

    public static function fromWorkerErrorLog(WorkerErrorLog $workerErrorLog): self
    {
        $errorBlocks = array_map(
            fn(string $block) => mb_strlen($block) > self::MAX_ERROR_BLOCK_LENGTH
                ? mb_substr($block, 0, self::MAX_ERROR_BLOCK_LENGTH) . ' [truncated]'
                : $block,
            array_slice(array_values($workerErrorLog->errorBlocks), 0, self::MAX_ERROR_BLOCKS),
        );

        return new self(
            $workerErrorLog->workerName,
            $workerErrorLog->status,
            $workerErrorLog->exitCode,
            $workerErrorLog->wasKilledByOrchestrator,
            $workerErrorLog->taskError,
            $workerErrorLog->lastAttemptedNode,
            NodeIdentifierExtractor::fromText($workerErrorLog->lastAttemptedNode),
            $errorBlocks,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'workerName' => $this->workerName,
            'status' => $this->status,
            'exitCode' => $this->exitCode,
            'wasKilledByOrchestrator' => $this->wasKilledByOrchestrator,
            'taskError' => $this->taskError,
            'lastAttemptedNode' => $this->lastAttemptedNode,
            'lastAttemptedNodeIdentifier' => $this->lastAttemptedNodeIdentifier,
            'errorBlocks' => $this->errorBlocks,
        ];
    }
}
