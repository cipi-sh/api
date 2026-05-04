<?php

namespace CipiApi\Services;

use Illuminate\Support\Facades\Cache;

class ServerStatusService
{
    public function __construct(protected CipiVersionService $version) {}

    public function status(): array
    {
        $ttl = (int) config('cipi.server.status_cache_ttl', 15);
        if ($ttl > 0) {
            return Cache::remember('cipi.server.status', $ttl, fn () => $this->build());
        }
        return $this->build();
    }

    protected function build(): array
    {
        return [
            'hostname' => gethostname() ?: '',
            'uptime_seconds' => $this->uptimeSeconds(),
            'cipi_version' => $this->version->version(),
            'os' => $this->osDescription(),
            'kernel' => php_uname('r'),
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'swap' => $this->swap(),
            'disk' => $this->disk(),
            'services' => $this->services(),
            'measured_at' => now()->toIso8601String(),
        ];
    }

    protected function uptimeSeconds(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime === false || $uptime === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($uptime));
        if (! $parts || ! isset($parts[0])) {
            return null;
        }
        return (int) floor((float) $parts[0]);
    }

    protected function osDescription(): string
    {
        $release = @file_get_contents('/etc/os-release');
        if ($release !== false) {
            if (preg_match('/^PRETTY_NAME="?(.+?)"?$/m', $release, $m)) {
                return trim($m[1]);
            }
        }
        return PHP_OS_FAMILY . ' ' . php_uname('r');
    }

    protected function cpu(): array
    {
        $cores = $this->cpuCores();

        $load = ['1m' => null, '5m' => null, '15m' => null];
        $loadavg = @file_get_contents('/proc/loadavg');
        if ($loadavg !== false) {
            $parts = preg_split('/\s+/', trim($loadavg));
            if (count($parts) >= 3) {
                $load = [
                    '1m' => (float) $parts[0],
                    '5m' => (float) $parts[1],
                    '15m' => (float) $parts[2],
                ];
            }
        }

        return [
            'cores' => $cores,
            'load_1m' => $load['1m'],
            'load_5m' => $load['5m'],
            'load_15m' => $load['15m'],
            'usage_percent' => $this->cpuUsagePercent(),
        ];
    }

    protected function cpuCores(): ?int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo === false) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
        return $count ?: null;
    }

    protected function readCpuStats(): ?array
    {
        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return null;
        }
        $line = strtok($stat, "\n");
        if ($line === false || ! str_starts_with($line, 'cpu ')) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts);
        $values = array_map('intval', $parts);
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
        $total = array_sum($values);
        return ['idle' => $idle, 'total' => $total];
    }

    protected function cpuUsagePercent(): ?float
    {
        $first = $this->readCpuStats();
        if (! $first) {
            return null;
        }
        usleep(120_000);
        $second = $this->readCpuStats();
        if (! $second) {
            return null;
        }
        $totalDiff = $second['total'] - $first['total'];
        $idleDiff = $second['idle'] - $first['idle'];
        if ($totalDiff <= 0) {
            return null;
        }
        $usage = (1.0 - $idleDiff / $totalDiff) * 100.0;
        return round(max(0.0, min(100.0, $usage)), 2);
    }

    protected function memory(): array
    {
        $info = $this->parseMeminfo();
        $totalKb = (int) ($info['MemTotal'] ?? 0);
        $availableKb = (int) ($info['MemAvailable'] ?? max(0, ($info['MemFree'] ?? 0) + ($info['Buffers'] ?? 0) + ($info['Cached'] ?? 0)));
        $usedKb = max(0, $totalKb - $availableKb);

        return [
            'total_mb' => (int) round($totalKb / 1024),
            'used_mb' => (int) round($usedKb / 1024),
            'free_mb' => (int) round($availableKb / 1024),
            'usage_percent' => $totalKb > 0 ? round($usedKb / $totalKb * 100, 2) : null,
        ];
    }

    protected function swap(): array
    {
        $info = $this->parseMeminfo();
        $totalKb = (int) ($info['SwapTotal'] ?? 0);
        $freeKb = (int) ($info['SwapFree'] ?? 0);
        $usedKb = max(0, $totalKb - $freeKb);

        return [
            'total_mb' => (int) round($totalKb / 1024),
            'used_mb' => (int) round($usedKb / 1024),
            'usage_percent' => $totalKb > 0 ? round($usedKb / $totalKb * 100, 2) : null,
        ];
    }

    protected function parseMeminfo(): array
    {
        $cached = Cache::get('cipi.server.meminfo');
        if (is_array($cached)) {
            return $cached;
        }
        $info = [];
        $content = @file_get_contents('/proc/meminfo');
        if ($content !== false) {
            foreach (preg_split('/\r?\n/', $content) as $line) {
                if (preg_match('/^([A-Za-z_()]+):\s+(\d+)/', $line, $m)) {
                    $info[$m[1]] = (int) $m[2];
                }
            }
        }
        Cache::put('cipi.server.meminfo', $info, 5);
        return $info;
    }

    protected function disk(): array
    {
        $disks = [];
        $output = [];
        @exec("df -PBM 2>/dev/null | awk 'NR>1 {print $1\"|\"$2\"|\"$3\"|\"$4\"|\"$5\"|\"$6}'", $output, $exitCode);
        if ($exitCode === 0) {
            foreach ($output as $line) {
                $parts = explode('|', $line);
                if (count($parts) < 6) {
                    continue;
                }
                [$source, $totalMb, $usedMb, $availMb, $usedPercent, $mount] = $parts;
                if (! str_starts_with($mount, '/')) {
                    continue;
                }
                if (str_starts_with($mount, '/dev') || str_starts_with($mount, '/proc') || str_starts_with($mount, '/sys') || str_starts_with($mount, '/run') || str_starts_with($mount, '/snap')) {
                    continue;
                }
                $disks[] = [
                    'mount' => $mount,
                    'source' => $source,
                    'total_mb' => (int) preg_replace('/\D/', '', $totalMb),
                    'used_mb' => (int) preg_replace('/\D/', '', $usedMb),
                    'available_mb' => (int) preg_replace('/\D/', '', $availMb),
                    'usage_percent' => (float) preg_replace('/[^\d.]/', '', $usedPercent),
                ];
            }
        }

        if ($disks === []) {
            $total = @disk_total_space('/') ?: 0;
            $free = @disk_free_space('/') ?: 0;
            $used = max(0, $total - $free);
            $disks[] = [
                'mount' => '/',
                'total_mb' => (int) round($total / 1024 / 1024),
                'used_mb' => (int) round($used / 1024 / 1024),
                'available_mb' => (int) round($free / 1024 / 1024),
                'usage_percent' => $total > 0 ? round($used / $total * 100, 2) : null,
            ];
        }

        return $disks;
    }

    public function services(): array
    {
        $services = (array) config('cipi.server.services', []);
        $out = [];
        foreach ($services as $service) {
            $service = trim((string) $service);
            if ($service === '') {
                continue;
            }
            $out[$service] = $this->serviceStatus($service);
        }
        return $out;
    }

    protected function serviceStatus(string $name): string
    {
        $output = [];
        $exit = 1;
        @exec('systemctl is-active ' . escapeshellarg($name) . ' 2>/dev/null', $output, $exit);
        $value = trim($output[0] ?? '');
        if ($value === '') {
            return 'unknown';
        }
        return $value;
    }
}
