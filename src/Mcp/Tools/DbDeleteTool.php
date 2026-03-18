<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tool;

#[Description('Permanently delete a database. Dispatches async job. Returns job_id for polling.')]
#[IsDestructive]
class DbDeleteTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('dbs-delete')) {
            return Response::text('Permission denied: dbs-delete required');
        }

        $name = $request->get('name');
        $command = 'db delete ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('db-delete', $command, ['name' => $name]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Database name to delete')->required(),
        ];
    }
}
