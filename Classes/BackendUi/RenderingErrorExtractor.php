<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\BackendUi;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class RenderingErrorExtractor
{
    /**
     * Extracts ERROR-prefixed lines and exception blocks from a raw log string.
     *
     * Log format produced by ContentReleaseLogger:
     *  - error()         => single line "[Renderer X] ERROR <message> [<json>]"
     *  - logException()  => one writeln with "<header>\n\n<msg>\n\n<trace>\n\n<json>"
     *
     * Strategy: split on blank lines into paragraphs; keep paragraphs that
     * contain an ERROR-prefixed line or PHP stack-trace markers, and also
     * include the paragraph immediately before a stack-trace paragraph (that
     * paragraph carries the exception message in logException output).
     *
     * @return string[]
     */
    public function extractErrorBlocks(string $log): array
    {
        $log = trim($log);
        if ($log === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $log) ?: [];
        $hits = [];
        foreach ($paragraphs as $index => $paragraph) {
            $hasError = (bool)preg_match('/(?:^|] )ERROR /m', $paragraph);
            $hasTrace = (bool)preg_match('/^#\d+ /m', $paragraph);
            if (!$hasError && !$hasTrace) {
                continue;
            }
            if ($hasTrace && $index > 0 && !isset($hits[$index - 1])) {
                $previous = $paragraphs[$index - 1];
                if (trim($previous) !== '') {
                    $hits[$index - 1] = $previous;
                }
            }
            $hits[$index] = $paragraph;
        }

        if ($hits !== []) {
            return $hits;
        }

        // No structured ERROR/trace matched. Drop INFO/DEBUG/NOTICE/WARN lines
        // (with or without an optional "[prefix] " section) plus a few known
        // operational status messages, and return whatever remains as a single
        // block — covers logs that use unfamiliar formats but still mark their
        // noise levels.
        $kept = [];
        foreach (explode("\n", $log) as $line) {
            if (preg_match('/^\s*(?:\[[^]]+]\s*)?(?:INFO|DEBUG|NOTICE|WARN(?:ING)?)\b/', $line)) {
                continue;
            }
            if (preg_match('/Restarting render worker\.?/', $line)) {
                continue;
            }
            $kept[] = $line;
        }
        $remaining = trim(implode("\n", $kept));
        return $remaining === '' ? [] : [$remaining];
    }

    /**
     * Returns the last node reference found in a worker log — the one the
     * worker was rendering when it failed.
     *
     * NodeRenderer logs a DEBUG line "Rendering document node variant" before
     * each render and a logException(...) entry on failure; both include the
     * node identifier in the JSON payload. The very last "node":"..." match in
     * the log is therefore the most likely failing node, whether the worker
     * threw an exception or got killed mid-render.
     */
    public function extractLastAttemptedNode(string $log): ?string
    {
        if (trim($log) === '') {
            return null;
        }

        if (!preg_match_all(
            '/"node"\s*:\s*"((?:\\\\.|[^"\\\\])*)"(?:\s*,\s*"nodeUri"\s*:\s*"((?:\\\\.|[^"\\\\])*)")?/',
            $log,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        $last = end($matches);
        $node = $this->decodeJsonString($last[1]);
        $uri = isset($last[2]) ? $this->decodeJsonString($last[2]) : '';
        return $uri !== '' ? sprintf('%s | %s', $node, $uri) : $node;
    }

    private function decodeJsonString(string $raw): string
    {
        $decoded = json_decode('"' . $raw . '"');
        return is_string($decoded) ? $decoded : str_replace('\\/', '/', $raw);
    }
}
