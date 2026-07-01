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
     * Tail log files with page-based reads from the end of each file.
     *
     * Tries direct reads first (when ACLs allow), then falls back to sudo.
     * Page 1 is the most recent chunk; higher pages return progressively older lines.
     *
     * @param  list<string>  $patterns
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    public function tailPaginated(array $patterns, int $page, int $perPage): array
    {
        return $this->tailPaginatedViaSudo($patterns, $page, $perPage);
    }

    /**
     * Read paginated logs via sudo only (PHP open_basedir blocks /home reads).
     *
     * @param  list<string>  $patterns
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    public function tailPaginatedViaSudo(array $patterns, int $page, int $perPage): array
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
     * @param  list<string>  $patterns
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    protected function tailLocalPaginated(array $patterns, int $page, int $perPage): array
    {
        $entries = [];

        foreach ($patterns as $pattern) {
            foreach ($this->expandPattern($pattern) as $path) {
                if (! is_readable($path)) {
                    continue;
                }

                $entry = $this->readPaginatedFromFile($path, $page, $perPage);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected function expandPattern(string $pattern): array
    {
        if (! $this->isSafeHostPathPattern($pattern)) {
            return [];
        }

        $files = glob($pattern) ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}|null
     */
    protected function readPaginatedFromFile(string $path, int $page, int $perPage): ?array
    {
        try {
            $file = new \SplFileObject($path, 'r');
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key() + 1;

            if ($totalLines <= 0) {
                return null;
            }

            $fromEnd = $page * $perPage;
            $start = max(0, $totalLines - $fromEnd);
            $file->seek($start);

            $buffer = [];
            while (! $file->eof() && count($buffer) < $perPage) {
                $line = $file->current();
                if (is_string($line)) {
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed !== '') {
                        $buffer[] = $trimmed;
                    }
                }
                $file->next();
            }

            if ($buffer === []) {
                return null;
            }

            return [
                'path' => $path,
                'total_lines' => $totalLines,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $this->totalPages($totalLines, $perPage),
                'lines' => $buffer,
            ];
        } catch (\Throwable) {
            return null;
        }
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

    protected function runSudoTailPaginated(string $pattern, int $page, int $perPage): array
    {
        if (! $this->isSafeHostPathPattern($pattern)) {
            return [];
        }

        $output = $this->runLogReader($pattern, $page, $perPage);

        if ($output === '') {
            return [];
        }

        return $this->parsePaginatedOutput($output, $page, $perPage);
    }

    /**
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    public function parsePaginatedOutput(string $raw, int $page, int $perPage): array
    {
        return $this->parsePaginatedSudoOutput($raw, $page, $perPage);
    }

    protected function runLogReader(string $pattern, int $page, int $perPage): string
    {
        if (is_executable('/usr/local/bin/cipi-read-app-logs')) {
            $output = [];
            $cmd = 'sudo /usr/local/bin/cipi-read-app-logs '
                . escapeshellarg($pattern) . ' '
                . $page . ' '
                . $perPage;
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0 && $output !== []) {
                return trim(implode("\n", $output));
            }
        }

        return $this->runSudoTailPaginatedViaBash($pattern, $page, $perPage);
    }

    protected function runSudoTailPaginatedViaBash(string $pattern, int $page, int $perPage): string
    {
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

        return $exitCode === 0 ? trim(implode("\n", $output)) : '';
    }

    /**
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    protected function parsePaginatedSudoOutput(string $raw, int $page, int $perPage): array
    {
        $entries = [];
        $marker = '===CIPI_LOG_FILE:';
        $endMarker = '===CIPI_LOG_END===';
        $offset = 0;

        while (($start = strpos($raw, $marker, $offset)) !== false) {
            $metaStart = $start + strlen($marker);
            $metaEnd = strpos($raw, '===', $metaStart);

            if ($metaEnd === false) {
                break;
            }

            $meta = substr($raw, $metaStart, $metaEnd - $metaStart);
            $contentStart = $metaEnd + 3;
            $contentEnd = strpos($raw, $endMarker, $contentStart);

            if ($contentEnd === false) {
                break;
            }

            $content = rtrim(substr($raw, $contentStart, $contentEnd - $contentStart), "\n");
            $lastColon = strrpos($meta, ':');
            $path = $lastColon === false ? $meta : substr($meta, 0, $lastColon);
            $totalLines = max(0, (int) ($lastColon === false ? 0 : substr($meta, $lastColon + 1)));
            $lines = $content === '' ? [] : (preg_split("/\r\n|\r|\n/", $content) ?: []);

            $entries[] = [
                'path' => $path,
                'total_lines' => $totalLines,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $this->totalPages($totalLines, $perPage),
                'lines' => $lines,
            ];

            $offset = $contentEnd + strlen($endMarker);
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
