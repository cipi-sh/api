<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tool;

#[Description('Delete an app. Validates app exists synchronously and dispatches async job. Returns job_id for polling.')]
#[IsDestructive]
class AppDeleteTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('apps-delete')) {
            return Response::text('Permission denied: apps-delete required');
        }

        $name = $request->get('name');
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }

        $command = 'app delete ' . escapeshellarg($name) . ' --force';
        $job = $this->jobs->dispatch('app-delete', $command, ['app' => $name]);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
        ];
    }
}
