<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Eel;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ModuleHelper implements ProtectedContextAwareInterface
{
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

            // Add line numbers
            $line = ($lineCount - $index) . ': ' . $line;

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
