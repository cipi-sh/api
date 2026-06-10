<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new database with auto-generated credentials. Dispatches async job. Returns job_id for polling.')]
class DbCreateTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $name = $request->get('name');
        if ($err = $this->validator->usernameError($name ?? '')) {
            return Response::text("Error: {$err}");
        }

        $command = 'db create --name=' . escapeshellarg($name);
        $job = $this->jobs->dispatch('db-create', $command, ['name' => $name]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Database name (3-32 lowercase alphanumeric)')->required(),
        ];
    }
}
