<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List all databases with their sizes. Dispatches async job. Returns job_id for polling.')]
class DbListTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('dbs-view')) {
            return Response::text('Permission denied: dbs-view required');
        }

        $job = $this->jobs->dispatch('db-list', 'db list', []);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
