<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Flowpack\DecoupledContentStore\BackendUi\Dto\WorkerErrorLog;
use Flowpack\Prunner\Dto\Job;
use Flowpack\Prunner\Dto\TaskResult;
use Flowpack\Prunner\PrunnerApiService;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class WorkerErrorLogAggregator
{
    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var RenderingErrorExtractor
     */
    protected $renderingErrorExtractor;

    /**
     * Exit code 143 = 128 + SIGTERM(15): orchestrator killed this worker after another one failed.
     */
    public const EXIT_CODE_SIGTERM = 143;

    /**
     * @return WorkerErrorLog[]
     */
    public function aggregate(Job $job): array
    {
        $renderTasks = $job->getTaskResults()
            ->filteredByPrefix('render_')
            ->withoutTasks('render_finished', 'render_orchestrator');

        $erroredTasks = [];
        foreach ($renderTasks as $task) {
            if ($task->getStatus() === TaskResult::STATUS_ERROR) {
                $erroredTasks[] = $task;
            }
        }

        if ($erroredTasks === []) {
            $orchestrator = $job->getTaskResults()->get('render_orchestrator');
            if ($orchestrator !== null && $orchestrator->getStatus() === TaskResult::STATUS_ERROR) {
                $erroredTasks[] = $orchestrator;
            }
        }

        // Real failures (non-SIGTERM) carry the actual error — show them first. Within both groups,
        // sort worker names naturally (1, 2, ..., 10 instead of 1, 10, ..., 2).
        usort($erroredTasks, static function (TaskResult $a, TaskResult $b): int {
            $killedComparison = ($a->getExitCode() === self::EXIT_CODE_SIGTERM ? 1 : 0)
                <=> ($b->getExitCode() === self::EXIT_CODE_SIGTERM ? 1 : 0);

            return $killedComparison !== 0 ? $killedComparison : strnatcasecmp($a->getName(), $b->getName());
        });

        $result = [];
        foreach ($erroredTasks as $task) {
            $blocks = [];
            $lastAttemptedNode = null;
            if ($task->getExitCode() !== self::EXIT_CODE_SIGTERM) {
                $logs = $this->prunnerApiService->loadJobLogs($job->getId(), $task->getName());
                $combined = $logs->getStderr() . "\n" . $logs->getStdout();
                $blocks = $this->renderingErrorExtractor->extractErrorBlocks($combined);
                $lastAttemptedNode = $this->renderingErrorExtractor->extractLastAttemptedNode($combined);
            }

            $result[] = new WorkerErrorLog(
                $task->getName(),
                $task->getStatus(),
                $task->getExitCode(),
                $task->getError() ?: null,
                $blocks,
                $lastAttemptedNode
            );
        }

        return $result;
    }
}
