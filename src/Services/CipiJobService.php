<?php

namespace CipiApi\Services;

use CipiApi\Jobs\RunCipiCommand;
use CipiApi\Models\CipiJob;

class CipiJobService
{
    public function dispatch(string $type, string $command, array $params = []): CipiJob
    {
        $job = CipiJob::create([
            'type' => $type,
            'params' => $params,
            'status' => 'pending',
        ]);

        RunCipiCommand::dispatch($job->id, $command);

        return $job;
    }
}
