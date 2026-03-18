<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tool;

#[Description('Restore a database from a backup file. Dispatches async job. Returns job_id for polling.')]
#[IsDestructive]
class DbRestoreTool extends Tool
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
        $file = $request->get('file');

        if (! preg_match('/^[a-zA-Z0-9_\-\/\.]+\.sql\.gz$/', $file ?? '')) {
            return Response::text('Error: Invalid backup file path. Must be a .sql.gz file with safe characters.');
        }

        $command = 'db restore ' . escapeshellarg($name) . ' ' . escapeshellarg($file);
        $job = $this->jobs->dispatch('db-restore', $command, ['name' => $name, 'file' => $file]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Database name to restore')->required(),
            'file' => $schema->string()->description('Path to backup file (.sql.gz)')->required(),
        ];
    }
}
