<?php

namespace CipiApi\Jobs;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiCliService;
use CipiApi\Services\CipiDbUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunCipiCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public string $command,
    ) {}

    public function handle(CipiCliService $cipi): void
    {
        $job = CipiJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $job->markRunning();
        try {
            // What the command prints is kept on the job as it comes, once a
            // second at most: GET /api/jobs/{id} shows it while the job runs.
            $saved = 0.0;
            $result = $cipi->runStreaming($this->command, function (string $soFar) use ($job, &$saved): void {
                $now = microtime(true);
                if ($now - $saved >= 1.0) {
                    $saved = $now;
                    $job->recordProgress($soFar);
                }
            });
            $job->markCompleted($result['output'], $result['exit_code']);
        } finally {
            $this->discardUpload($job);
        }
    }

    /**
     * A dump uploaded for this restore is deleted once the restore has run,
     * whatever its result: it may hold a whole database.
     */
    protected function discardUpload(CipiJob $job): void
    {
        $uploadId = is_array($job->params) ? ($job->params['upload_id'] ?? null) : null;
        if ($job->type !== 'db-restore' || ! is_string($uploadId) || $uploadId === '') {
            return;
        }
        try {
            app(CipiDbUploadService::class)->discard($uploadId);
        } catch (\Throwable) {
            // Best effort: the daily sweep takes what is left.
        }
    }
}
