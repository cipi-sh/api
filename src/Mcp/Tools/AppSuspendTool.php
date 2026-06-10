<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Suspend an app: take it offline with an HTTP 503 page without deleting it. Validates app exists synchronously and dispatches async job. Returns job_id for polling.')]
class AppSuspendTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $name = $request->get('name');
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($this->validator->isSuspended($name)) {
            return Response::text("Error: App '{$name}' is already suspended");
        }

        $command = 'app suspend ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('app-suspend', $command, ['app' => $name]);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
        ];
    }
}
