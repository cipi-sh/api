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

#[Description('Remove alias from app. Validates app and alias exist synchronously, dispatches async job. Returns job_id for polling.')]
#[IsDestructive]
class AliasRemoveTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('aliases-delete')) {
            return Response::text('Permission denied: aliases-delete required');
        }

        $name = $request->get('name');
        $alias = $request->get('alias');

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        $aliases = $this->validator->getAppAliases($name);
        if (! in_array($alias, $aliases, true)) {
            return Response::text("Error: Alias '{$alias}' not found for app '{$name}'");
        }

        $command = 'alias remove ' . escapeshellarg($name) . ' ' . escapeshellarg($alias);
        $job = $this->jobs->dispatch('alias-delete', $command, ['app' => $name, 'alias' => $alias]);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'alias' => $schema->string()->description('Domain alias to remove')->required(),
        ];
    }
}
