<?php

namespace CipiApi\Console\Commands;

use CipiApi\Models\ServerMetric;
use CipiApi\Services\ServerStatusService;
use Illuminate\Console\Command;

class RecordServerMetrics extends Command
{
    protected $signature = 'cipi:record-server-metrics
                            {--prune : Also delete metrics older than the configured retention window}';

    protected $description = 'Capture a snapshot of server metrics into cipi_server_metrics (intended for cron)';

    public function handle(ServerStatusService $status): int
    {
        if (! (bool) config('cipi.metrics.enabled', true)) {
            $this->info('Metrics collection disabled (CIPI_METRICS_ENABLED=false).');
            return self::SUCCESS;
        }

        $snapshot = $status->status();

        $disks = (array) ($snapshot['disk'] ?? []);
        $rootUsage = null;
        foreach ($disks as $disk) {
            if (($disk['mount'] ?? null) === '/') {
                $rootUsage = isset($disk['usage_percent']) ? (float) $disk['usage_percent'] : null;
                break;
            }
        }

        ServerMetric::create([
            'recorded_at' => now(),
            'load_1m' => $snapshot['cpu']['load_1m'] ?? null,
            'load_5m' => $snapshot['cpu']['load_5m'] ?? null,
            'load_15m' => $snapshot['cpu']['load_15m'] ?? null,
            'cpu_usage_percent' => $snapshot['cpu']['usage_percent'] ?? null,
            'memory_total_mb' => $snapshot['memory']['total_mb'] ?? null,
            'memory_used_mb' => $snapshot['memory']['used_mb'] ?? null,
            'memory_usage_percent' => $snapshot['memory']['usage_percent'] ?? null,
            'swap_total_mb' => $snapshot['swap']['total_mb'] ?? null,
            'swap_used_mb' => $snapshot['swap']['used_mb'] ?? null,
            'disk_root_usage_percent' => $rootUsage,
            'disks' => $disks,
            'services' => $snapshot['services'] ?? null,
        ]);

        if ($this->option('prune')) {
            $retention = (int) config('cipi.metrics.retention_days', 30);
            if ($retention > 0) {
                $threshold = now()->subDays($retention);
                $deleted = ServerMetric::where('recorded_at', '<', $threshold)->delete();
                if ($deleted > 0) {
                    $this->info("Pruned {$deleted} old metric rows (older than {$retention} days).");
                }
            }
        }

        return self::SUCCESS;
    }
}
