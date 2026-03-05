<?php

namespace CipiApi\Jobs;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiCliService;
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
        $result = $cipi->run($this->command);
        $job->markCompleted($result['output'], $result['exit_code']);
    }
}
