<?php

namespace CipiApi\Services;

/**
 * Reads Laravel logs for the host application running cipi/api.
 */
class CipiApiLogService
{
    public function __construct(
        protected CipiLogReader $logReader,
    ) {}

    public function read(?string $date = null, int $lines = CipiLogReader::DEFAULT_LINES): string
    {
        $paths = $this->resolvePaths($date);

        if ($paths === []) {
            return 'No Cipi API log files found in ' . storage_path('logs') . '.';
        }

        $content = $this->logReader->tailLocal($paths, $lines);

        return $content !== '' ? $content : 'Log files exist but contain no readable lines.';
    }

    /**
     * @return list<string>
     */
    protected function resolvePaths(?string $date): array
    {
        $dir = storage_path('logs');

        if ($date !== null && $date !== '') {
            $path = "{$dir}/laravel-{$date}.log";

            return file_exists($path) ? [$path] : [];
        }

        $paths = [];
        $single = "{$dir}/laravel.log";
        if (file_exists($single)) {
            $paths[] = $single;
        }

        $today = "{$dir}/laravel-" . date('Y-m-d') . '.log';
        if (file_exists($today) && ! in_array($today, $paths, true)) {
            $paths[] = $today;
        }

        if ($paths === []) {
            $latest = $this->logReader->latestDailyLog($dir);
            if ($latest !== null) {
                $paths[] = $latest;
            }
        }

        return $paths;
    }
}
