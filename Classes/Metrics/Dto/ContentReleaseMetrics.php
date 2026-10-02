<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Metrics\Dto;

use DateTimeInterface;
use Flowpack\DecoupledContentStore\BackendUi\Dto\WorkerErrorLog;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingStatistics;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata;
use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * Everything worth keeping about one content release, in a shape an external log aggregator can index.
 *
 * Redis holds only the last `contentReleaseRetentionCount` releases, so a release which failed is gone
 * before anybody gets to look at it. This DTO is what survives that.
 */
#[Flow\Proxy(false)]
final class ContentReleaseMetrics implements JsonSerializable
{
    /**
     * Errors are shipped inline, so a release with thousands of failing documents must not produce a log line
     * nobody can read. `renderingErrorCount` always carries the real number.
     */
    private const MAX_REPORTED_ERRORS = 50;

    /**
     * The orchestrator SIGTERMs the remaining workers once one of them failed, so a broken release produces one
     * entry per worker - and only the first few carry a real error.
     */
    private const MAX_REPORTED_WORKER_ERRORS = 10;

    /**
     * @param RenderingIteration[] $iterations
     * @param RenderingError[] $renderingErrors
     * @param WorkerError[]|null $workerErrors NULL when the worker logs could not be read
     */
    private function __construct(
        public readonly string $contentReleaseIdentifier,
        public readonly string $prunnerJobId,
        public readonly string $status,
        public readonly ?string $workspaceName,
        public readonly ?string $accountId,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly ?int $durationSeconds,
        public readonly ?float $sizeMegabytes,
        public readonly int $documentCount,
        public readonly int $newlyRenderedDocumentCount,
        public readonly int $renderingCount,
        public readonly int $iterationCount,
        public readonly array $iterations,
        public readonly int $renderingErrorCount,
        public readonly array $renderingErrors,
        public readonly ?array $workerErrors,
    ) {}

    /**
     * @param RenderingStatistics[] $renderingStatistics one entry per iteration of the rendering loop
     * @param string[] $rawRenderingErrors as stored by RedisRenderingErrorManager
     * @param WorkerErrorLog[]|null $workerErrorLogs NULL when the worker logs could not be read
     * @param DateTimeInterface $now the moment the report is taken, used as end time for a release which has none
     */
    public static function create(
        ContentReleaseIdentifier $contentReleaseIdentifier,
        ContentReleaseMetadata $metadata,
        int $documentCount,
        array $renderingStatistics,
        array $rawRenderingErrors,
        ?array $workerErrorLogs,
        DateTimeInterface $now,
    ): self {
        $iterations = [];
        $renderingCount = 0;
        foreach (array_values($renderingStatistics) as $index => $statistics) {
            $iteration = RenderingIteration::fromRenderingStatistics($index + 1, $statistics);
            $iterations[] = $iteration;
            $renderingCount += $iteration->completedRenderings;
        }

        $startTime = $metadata->getStartTime();
        // The end time is written when the rendering finished, so the duration covers enumeration and rendering -
        // not the transfer and switch tasks which run after them.
        $endTime = $metadata->getEndTime();
        // A release which was aborted never gets an end time written, so its duration is measured up to the
        // moment this report is taken - which is the moment the pipeline gave up on it.
        $durationEnd = $endTime ?? $now;

        $rawRenderingErrors = array_values($rawRenderingErrors);
        $renderingErrors = array_map(
            fn(string $rawEntry) => RenderingError::fromRawEntry($rawEntry),
            array_slice($rawRenderingErrors, 0, self::MAX_REPORTED_ERRORS),
        );

        $workerErrors = $workerErrorLogs === null
            ? null
            : array_map(
                fn(WorkerErrorLog $workerErrorLog) => WorkerError::fromWorkerErrorLog($workerErrorLog),
                array_slice(array_values($workerErrorLogs), 0, self::MAX_REPORTED_WORKER_ERRORS),
            );

        return new self(
            $contentReleaseIdentifier->getIdentifier(),
            $metadata->getPrunnerJobId()->getIdentifier(),
            $metadata->getStatus()->getStatus(),
            $metadata->getWorkspaceName(),
            $metadata->getAccountId(),
            $startTime?->format(DateTimeInterface::RFC3339_EXTENDED),
            $endTime?->format(DateTimeInterface::RFC3339_EXTENDED),
            $startTime !== null ? $durationEnd->getTimestamp() - $startTime->getTimestamp() : null,
            $metadata->getContentReleaseSize(),
            $documentCount,
            // The first iteration copies everything which is still complete in the content cache and schedules
            // the rest - so its scheduled count is the number of documents this release rendered anew.
            $iterations !== [] ? $iterations[0]->scheduledRenderings : 0,
            $renderingCount,
            count($iterations),
            $iterations,
            count($rawRenderingErrors),
            $renderingErrors,
            $workerErrors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'contentReleaseIdentifier' => $this->contentReleaseIdentifier,
            'prunnerJobId' => $this->prunnerJobId,
            'status' => $this->status,
            'workspaceName' => $this->workspaceName,
            'accountId' => $this->accountId,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'durationSeconds' => $this->durationSeconds,
            'sizeMegabytes' => $this->sizeMegabytes,
            'documentCount' => $this->documentCount,
            'newlyRenderedDocumentCount' => $this->newlyRenderedDocumentCount,
            'renderingCount' => $this->renderingCount,
            'iterationCount' => $this->iterationCount,
            'iterations' => $this->iterations,
            'renderingErrorCount' => $this->renderingErrorCount,
            'renderingErrors' => $this->renderingErrors,
            'workerErrors' => $this->workerErrors,
        ];
    }
}
