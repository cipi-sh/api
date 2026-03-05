<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Edit an app. Validates app exists and PHP version synchronously, dispatches async job. Returns job_id for polling.')]
class AppEditTool extends Tool
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('apps-edit')) {
            return Response::text('Permission denied: apps-edit required');
        }

        $name = $request->get('name');
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }

        $params = array_filter([
            'php' => $request->get('php'),
            'branch' => $request->get('branch'),
            'repository' => $request->get('repository'),
        ]);
        if (empty($params)) {
            return Response::text('Error: Nothing to change. Provide php, branch, or repository.');
        }
        if (isset($params['php'])) {
            if ($err = $this->validator->phpVersionError($params['php'])) {
                return Response::text("Error: {$err}");
            }
        }

        $args = ['app edit', escapeshellarg($name)];
        foreach ($params as $k => $v) {
            $args[] = '--' . $k . '=' . escapeshellarg((string) $v);
        }

        $job = $this->jobs->dispatch('app-edit', implode(' ', $args), ['app' => $name] + $params);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll GET /api/jobs/{$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'php' => $schema->string()->description('PHP version'),
            'branch' => $schema->string()->description('Branch'),
            'repository' => $schema->string()->description('Repository URL'),
        ];
    }
}
