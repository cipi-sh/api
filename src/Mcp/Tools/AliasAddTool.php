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

#[Description('Add alias to app. Validates domain and app synchronously, dispatches async job. Returns job_id for polling.')]
class AliasAddTool extends Tool
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
        [$alias, $error] = McpArgValidator::requiredString($request, 'alias');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($err = $this->validator->domainError($alias ?? '')) {
            return Response::text("Error: {$err}");
        }
        $usedBy = $this->validator->domainUsedBy($alias);
        if ($usedBy) {
            return Response::text("Error: Domain '{$alias}' is already used by app '{$usedBy}'");
        }

        $command = 'alias add ' . escapeshellarg($name) . ' ' . escapeshellarg($alias);
        $job = $this->jobs->dispatch('alias-create', $command, ['app' => $name, 'alias' => $alias]);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'alias' => $schema->string()->description('Domain alias')->required(),
        ];
    }
}
