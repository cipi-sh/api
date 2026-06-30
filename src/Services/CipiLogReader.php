<?php

namespace CipiApi\Services;

/**
 * Reads the last N lines from log files on the host (with sudo fallback when needed).
 */
class CipiLogReader
{
    public const DEFAULT_LINES = 50;

    public const MAX_LINES = 1000;

    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 1000;

    public function clampLines(int $lines): int
    {
        return min(max(1, $lines), self::MAX_LINES);
    }

    public function clampPage(int $page): int
    {
        return max(1, $page);
    }

    public function clampPerPage(int $perPage): int
    {
        return min(max(1, $perPage), self::MAX_PER_PAGE);
    }

    public function totalPages(int $totalLines, int $perPage): int
    {
        if ($totalLines <= 0) {
            return 0;
        }

        return (int) ceil($totalLines / $this->clampPerPage($perPage));
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
     * Tail files via sudo bash with page-based reads from the end of each file.
     *
     * Page 1 is the most recent chunk; higher pages return progressively older lines.
     *
     * @param  list<string>  $patterns
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    public function tailViaSudoPaginated(array $patterns, int $page, int $perPage): array
    {
        $page = $this->clampPage($page);
        $perPage = $this->clampPerPage($perPage);
        $entries = [];

        foreach ($patterns as $pattern) {
            foreach ($this->runSudoTailPaginated($pattern, $page, $perPage) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
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

    /**
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    protected function runSudoTailPaginated(string $pattern, int $page, int $perPage): array
    {
        if (! $this->isSafeHostPathPattern($pattern)) {
            return [];
        }

        $pageArg = (string) $page;
        $perPageArg = (string) $perPage;

        $inner = 'shopt -s nullglob; '
            . 'for f in ' . $pattern . '; do '
            . 'if [ -f "$f" ]; then '
            . 'total=$(wc -l < "$f" | tr -d " \\n"); '
            . 'from_end=$(( ' . $pageArg . ' * ' . $perPageArg . ' )); '
            . 'echo "===CIPI_LOG_FILE:$f:$total==="; '
            . '/usr/bin/tail -n "$from_end" "$f" | /usr/bin/head -n ' . $perPageArg . '; '
            . 'echo "===CIPI_LOG_END==="; '
            . 'fi; '
            . 'done 2>/dev/null';

        $output = [];
        exec('sudo /bin/bash -c ' . escapeshellarg($inner), $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return [];
        }

        return $this->parsePaginatedSudoOutput(implode("\n", $output), $page, $perPage);
    }

    /**
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    protected function parsePaginatedSudoOutput(string $raw, int $page, int $perPage): array
    {
        $entries = [];
        $blocks = preg_split('/===CIPI_LOG_FILE:(.+?)===(.*?)===CIPI_LOG_END===/s', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        for ($i = 1; $i < count($blocks); $i += 3) {
            $meta = $blocks[$i];
            $content = rtrim($blocks[$i + 1] ?? '', "\n");
            [$path, $totalLinesRaw] = array_pad(explode(':', $meta, 2), 2, '0');
            $totalLines = max(0, (int) $totalLinesRaw);
            $lines = $content === '' ? [] : preg_split("/\r\n|\r|\n/", $content) ?: [];

            $entries[] = [
                'path' => $path,
                'total_lines' => $totalLines,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $this->totalPages($totalLines, $perPage),
                'lines' => $lines,
            ];
        }

        return $entries;
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
