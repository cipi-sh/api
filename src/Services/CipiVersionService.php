<?php

namespace CipiApi\Services;

use Illuminate\Support\Facades\Cache;

class CipiVersionService
{
    public function version(): ?string
    {
        return Cache::remember('cipi.version', now()->addMinutes(10), function () {
            $file = (string) config('cipi.version_file', '/etc/cipi/version');
            if ($file !== '' && is_readable($file)) {
                $content = trim((string) @file_get_contents($file));
                if ($content !== '') {
                    return $this->extractVersion($content);
                }
            }

            $binary = (string) config('cipi.cipi_binary', '/usr/local/bin/cipi');
            if ($binary !== '' && is_executable($binary)) {
                $output = [];
                @exec(escapeshellarg($binary) . ' --version 2>/dev/null', $output, $exitCode);
                if ($exitCode === 0 && ! empty($output)) {
                    $version = $this->extractVersion(implode("\n", $output));
                    if ($version) {
                        return $version;
                    }
                }
            }

            return null;
        });
    }

    protected function extractVersion(string $text): ?string
    {
        if (preg_match('/(\d+\.\d+(?:\.\d+)?(?:-[A-Za-z0-9._-]+)?)/', $text, $m)) {
            return $m[1];
        }
        return null;
    }
}
