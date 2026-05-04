<?php

namespace CipiApi\Jobs;

use CipiApi\Events\JobStateChanged;
use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiCliService;
use CipiApi\Services\JobLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

class RunCipiCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public string $command,
    ) {}

    public function handle(CipiCliService $cipi, JobLogService $logs): void
    {
        $job = CipiJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $logPath = $logs->ensureFor($job->id);
        if (empty($job->log_path)) {
            $job->forceFill(['log_path' => $logPath])->save();
        }

        $job->markRunning();
        Event::dispatch(new JobStateChanged($job->fresh() ?? $job, 'started'));

        $result = $cipi->runStreaming($this->command, $logPath);

        $job->markCompleted($result['output'], $result['exit_code']);

        $fresh = $job->fresh() ?? $job;
        Event::dispatch(new JobStateChanged(
            $fresh,
            $result['exit_code'] === 0 ? 'completed' : 'failed'
        ));
    }
}
