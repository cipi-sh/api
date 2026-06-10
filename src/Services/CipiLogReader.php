<?php

namespace CipiApi\Services;

/**
 * Reads the last N lines from log files on the host (with sudo fallback when needed).
 */
class CipiLogReader
{
    public const DEFAULT_LINES = 50;

    public const MAX_LINES = 1000;

    public function clampLines(int $lines): int
    {
        return min(max(1, $lines), self::MAX_LINES);
    }

    /**
     * Tail local files readable by the current PHP process.
     *
     * @param  list<string>  $paths
     */
    public function tailLocal(array $paths, int $lines): string
    {
        $lines = $this->clampLines($lines);
        $chunks = [];

        foreach ($paths as $path) {
            if (! is_readable($path)) {
                continue;
            }

            $content = $this->readTailFromFile($path, $lines);
            if ($content === '') {
                continue;
            }

            $chunks[] = $this->formatChunk($path, $content);
        }

        return implode("\n", $chunks);
    }

    /**
     * Tail files via sudo bash (supports shell globs). Mirrors `cipi app logs` paths.
     *
     * @param  list<string>  $patterns  File paths or glob patterns (e.g. /home/app/logs/nginx-*.log)
     */
    public function tailViaSudo(array $patterns, int $lines): string
    {
        $lines = $this->clampLines($lines);
        $chunks = [];

        foreach ($patterns as $pattern) {
            $content = $this->runSudoTail($pattern, $lines);
            if ($content === '') {
                continue;
            }

            $chunks[] = $content;
        }

        return implode("\n", $chunks);
    }

    /**
     * Resolve the newest laravel-*.log in a directory (local read).
     */
    public function latestDailyLog(string $directory): ?string
    {
        $files = glob(rtrim($directory, '/') . '/laravel-*.log') ?: [];
        if ($files === []) {
            return null;
        }

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    protected function readTailFromFile(string $path, int $lines): string
    {
        try {
            $file = new \SplFileObject($path, 'r');
            $file->seek(PHP_INT_MAX);
            $lastLine = $file->key();
            $start = max(0, $lastLine - $lines + 1);
            $file->seek($start);

            $buffer = [];
            while (! $file->eof()) {
                $line = $file->current();
                if (is_string($line) && $line !== '') {
                    $buffer[] = rtrim($line, "\r\n");
                }
                $file->next();
            }

            return implode("\n", $buffer);
        } catch (\Throwable) {
            return '';
        }
    }

    protected function runSudoTail(string $pattern, int $lines): string
    {
        if (! $this->isSafeHostPathPattern($pattern)) {
            return '';
        }

        $inner = 'shopt -s nullglob; '
            . 'for f in ' . $pattern . '; do '
            . 'if [ -f "$f" ]; then '
            . 'echo "=== $f ==="; '
            . '/usr/bin/tail -n ' . $lines . ' "$f"; '
            . 'echo; '
            . 'fi; '
            . 'done 2>/dev/null';

        $output = [];
        exec('sudo /bin/bash -c ' . escapeshellarg($inner), $output, $exitCode);

        return $exitCode === 0 ? trim(implode("\n", $output)) : '';
    }

    protected function formatChunk(string $path, string $content): string
    {
        return "=== {$path} ===\n" . $content;
    }

    protected function isSafeHostPathPattern(string $pattern): bool
    {
        return (bool) preg_match('#^/home/[a-z][a-z0-9]*/#', $pattern);
    }
}
