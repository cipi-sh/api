<?php

namespace CipiApi\Services;

use CipiApi\Exceptions\DisallowedCipiCommandException;
use CipiApi\Exceptions\UnmappedJobTypeException;
use CipiApi\Jobs\RunCipiCommand;
use CipiApi\Models\CipiJob;

class CipiJobService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @throws DisallowedCipiCommandException If {@see CipiCliService::commandIsPermitted} is false (misconfiguration).
     * @throws UnmappedJobTypeException If the type has no entry in {@see CipiJobAbilities::ABILITIES} (misconfiguration).
     */
    public function dispatch(string $type, string $command, array $params = []): CipiJob
    {
        if (! $this->cli->commandIsPermitted($command)) {
            throw new DisallowedCipiCommandException($command);
        }
        if (CipiJobAbilities::forType($type) === null) {
            throw new UnmappedJobTypeException($type);
        }

        $job = CipiJob::create([
            'type' => $type,
            'params' => $params,
            'status' => 'pending',
        ]);

        RunCipiCommand::dispatch($job->id, $command);

        return $job;
    }
}
