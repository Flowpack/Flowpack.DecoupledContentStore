<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Eel;

use Flowpack\Prunner\Dto\TaskResult;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ModuleHelper implements ProtectedContextAwareInterface
{
    /**
     * Sort task results by name using a natural order, so that numeric render workers appear as
     * 1, 2, ..., 10, 11 instead of the lexicographic 1, 10, 11, ..., 2, 20. Named workers follow
     * the numeric ones alphabetically.
     *
     * @param iterable<TaskResult> $tasks
     * @return TaskResult[]
     */
    public function sortTasksNaturally(?iterable $tasks): array
    {
        if (!$tasks) {
            return [];
        }

        $sortedTasks = is_array($tasks) ? $tasks : iterator_to_array($tasks, false);
        usort($sortedTasks, static function (TaskResult $a, TaskResult $b): int {
            return strnatcasecmp($a->getName(), $b->getName());
        });

        return $sortedTasks;
    }

    public function formatStdOutput(?string $stdOut): string
    {
        if (!$stdOut) {
            return '';
        }

        $escapedString = $stdOut;
        $lines = array_reverse(array_filter(preg_split("/\r\n|\n|\r/", $escapedString)));
        $lineCount = count($lines);

        $formattedLines = array_map(static function (string $line, int $index) use ($lineCount) {
            // Extract additional JSON data
            preg_match("/\{.*}/", $line, $jsonMatches);
            $jsonData = array_filter(array_map(static function (string $match) {
                $matchData = json_decode($match, true);
                return $matchData['message'] ?? $matchData;
            }, $jsonMatches));
            if (count($jsonData) === 1) {
                $jsonData = array_shift($jsonData);
            }

            // Remove additional JSON data from log line
            $line = preg_replace("/\{.*}/", '', $line);

            // Add highlighting for log levels
            $line = preg_replace("/(DEBUG|WARNING|ERROR|INFO): (.*)/", "<span class=\"log-level-$1\">$1:</span> <span class=\"log-content-$1\">$2</span>", htmlSpecialChars($line));

            // Add line numbers. The lines are reversed (newest first), so the numbering counts down to 1.
            $line = ($lineCount - $index + 1) . ': ' . $line;

            // Insert formatted JSON data
            if ($jsonData) {
                $isDebug = strpos($line, 'DEBUG:') !== false;
                $jsonString = "\n<span class=\"json\">" . json_encode($jsonData, JSON_PRETTY_PRINT) . "</span>";
                $line = $isDebug ? '<details class="json-details">' . '<summary>' . $line . '</summary>' . $jsonString . '</details>' : $line . $jsonString;
            }

            // Wrap in <pre> tags
            return '<pre>' . $line . '</pre>';
        }, $lines, range(1, $lineCount));

        return implode("\n", $formattedLines);
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
