<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Restart an SSR Node app with a blue/green switch — no downtime (cipi node restart). Dispatches an async job; poll JobShow. Requires Cipi CLI ≥ 5.4.1.')]
class NodeRestartTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if (! $this->validator->isNodeApp($name)) {
            return Response::text("Error: App '{$name}' is not a Node app");
        }

        $command = 'node restart ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('node-restart', $command, ['app' => $name]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name (Node app)')->required(),
        ];
    }
}
