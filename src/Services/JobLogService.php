<?php

namespace CipiApi\Services;

class JobLogService
{
    public function basePath(): string
    {
        $configured = (string) config('cipi.job_logs.path', '');
        if ($configured === '') {
            $configured = function_exists('storage_path')
                ? storage_path('app/cipi-job-logs')
                : sys_get_temp_dir() . '/cipi-job-logs';
        }
        if (! is_dir($configured)) {
            @mkdir($configured, 0775, true);
        }
        return rtrim($configured, '/');
    }

    public function pathFor(string $jobId): string
    {
        return $this->basePath() . '/' . $this->normalizeId($jobId) . '.log';
    }

    public function ensureFor(string $jobId): string
    {
        $path = $this->pathFor($jobId);
        if (! file_exists($path)) {
            @touch($path);
            @chmod($path, 0664);
        }
        return $path;
    }

    public function tail(string $jobId, int $fromByte = 0, int $maxBytes = 65_536): array
    {
        $path = $this->pathFor($jobId);
        if (! file_exists($path)) {
            return [
                'available' => false,
                'size' => 0,
                'next_byte' => $fromByte,
                'chunk' => '',
            ];
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($fromByte < 0) {
            $fromByte = 0;
        }
        if ($fromByte > $size) {
            $fromByte = $size;
        }

        $chunk = '';
        $nextByte = $fromByte;
        if ($size > $fromByte) {
            $handle = @fopen($path, 'rb');
            if ($handle) {
                if (@fseek($handle, $fromByte) === 0) {
                    $remaining = min($maxBytes, $size - $fromByte);
                    $chunk = (string) @fread($handle, $remaining);
                    $nextByte = $fromByte + strlen($chunk);
                }
                @fclose($handle);
            }
        }

        return [
            'available' => true,
            'size' => $size,
            'next_byte' => $nextByte,
            'chunk' => $chunk,
        ];
    }

    public function purgeOlderThan(int $days): int
    {
        $base = $this->basePath();
        if (! is_dir($base)) {
            return 0;
        }
        $threshold = time() - max(1, $days) * 86400;
        $count = 0;
        foreach ((array) glob($base . '/*.log') as $file) {
            if (! is_file($file)) {
                continue;
            }
            if (@filemtime($file) < $threshold) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    protected function normalizeId(string $jobId): string
    {
        return preg_replace('/[^A-Za-z0-9\-_]/', '', $jobId) ?? '';
    }
}
