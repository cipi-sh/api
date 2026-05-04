<?php

namespace CipiApi\Console\Commands;

use CipiApi\Services\JobLogService;
use Illuminate\Console\Command;

class PruneJobLogs extends Command
{
    protected $signature = 'cipi:prune-job-logs {--days= : Override retention days}';

    protected $description = 'Delete Cipi job log files older than the configured retention window';

    public function handle(JobLogService $logs): int
    {
        $days = (int) ($this->option('days') ?? config('cipi.job_logs.retention_days', 14));
        if ($days < 1) {
            $this->info('Retention disabled (days < 1).');
            return self::SUCCESS;
        }
        $deleted = $logs->purgeOlderThan($days);
        $this->info("Pruned {$deleted} job log files older than {$days} days.");
        return self::SUCCESS;
    }
}
