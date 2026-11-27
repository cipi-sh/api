<?php

namespace CipiApi\Jobs;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiCliService;
use CipiApi\Services\CipiDbUploadService;
use CipiApi\Services\CipiJobTimeout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;

class RunCipiCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds before the worker stops the job. Its own for each command
     * ({@see CipiJobTimeout}): a deploy gets an hour, everything else ten
     * minutes. It travels with the job, and counts over the worker's --timeout.
     */
    public int $timeout = CipiJobTimeout::SECONDS;
    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public string $command,
    ) {
        $this->timeout = CipiJobTimeout::forCommand($command);
    }

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
     * The worker gave the job up: its time was over, or handle() threw. Nobody
     * wrote the end of it, so the job stayed "running" for good — and with it
     * the panel that was following it. It is closed as failed, with what the
     * command had printed and one line that says why.
     */
    public function failed(?\Throwable $e = null): void
    {
        try {
            $job = CipiJob::find($this->jobId);
            if (! $job || ! in_array($job->status, ['pending', 'running'], true)) {
                return;
            }
            if ($e instanceof TimeoutExceededException) {
                $job->markCompleted(
                    CipiJobTimeout::stoppedOutput($job->output, $this->command, $this->timeout),
                    CipiJobTimeout::EXIT_CODE,
                );
            } else {
                // The reason is in the API's log: an exception's text is not for a job's output.
                $job->markCompleted(
                    trim(rtrim((string) $job->output) . "\n\n" . '[cipi-api] The job ended with an error before the result of the command was saved.'),
                    1,
                );
            }
            $this->discardUpload($job);
        } catch (\Throwable) {
            // The worker is about to stop: nothing more to do from here.
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
