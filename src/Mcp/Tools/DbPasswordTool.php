<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Regenerate the MariaDB password for a database and update the .env file. Dispatches async job. Returns job_id for polling.')]
class DbPasswordTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('dbs-manage')) {
            return Response::text('Permission denied: dbs-manage required');
        }

        $name = $request->get('name');
        $command = 'db password ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('db-password', $command, ['name' => $name]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Database/app name')->required(),
        ];
    }
}
