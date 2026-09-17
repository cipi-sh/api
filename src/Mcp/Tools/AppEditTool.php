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

#[Description('Edit an app: PHP, branch, repository, primary domain, or Node fields (mode/build/start/output/health_path on Node apps; node_version also pins a Laravel app to a Node major, "default" follows the server again). Validates synchronously, dispatches async job. Returns job_id for polling.')]
class AppEditTool extends Tool
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

        $params = $this->validator->filterUnchangedAppEditFields($name, array_filter([
            'php' => $request->get('php'),
            'branch' => $request->get('branch'),
            'repository' => $request->get('repository'),
            'domain' => $request->get('domain'),
        ], fn ($v) => $v !== null && $v !== ''));

        $nodeFields = array_filter([
            'node' => $request->get('node'),
            'node_version' => $request->get('node_version'),
            'build' => $request->get('build'),
            'start' => $request->get('start'),
            'output' => $request->get('output'),
            'health_path' => $request->get('health_path'),
        ], fn ($v) => is_string($v) && trim($v) !== '');
        $nodeFields = array_map(fn ($v) => trim($v), $nodeFields);

        if (empty($params) && empty($nodeFields)) {
            return Response::text('Error: Nothing to change. Provide a different php, branch, repository, domain, or a Node field.');
        }
        if (isset($params['php'])) {
            if ($err = $this->validator->phpInstalledError($params['php'])) {
                return Response::text("Error: {$err}");
            }
        }
        if (isset($params['domain'])) {
            if ($err = $this->validator->domainError($params['domain'])) {
                return Response::text("Error: {$err}");
            }
            $usedBy = $this->validator->domainUsedBy($params['domain'], $name);
            if ($usedBy) {
                return Response::text("Error: Domain '{$params['domain']}' is already used by app '{$usedBy}'");
            }
        }
        if (! empty($nodeFields)) {
            $isNodeApp = $this->validator->isNodeApp($name);
            foreach (['node', 'build', 'start', 'output', 'health_path'] as $nodeOnly) {
                if (isset($nodeFields[$nodeOnly]) && ! $isNodeApp) {
                    return Response::text("Error: '{$nodeOnly}' only applies to Node apps");
                }
            }
            foreach ([
                $this->validator->nodeModeError($nodeFields['node'] ?? null),
                $this->validator->nodeVersionError($nodeFields['node_version'] ?? null, ! $isNodeApp),
                $this->validator->nodeCommandError($nodeFields['build'] ?? null, 'build command'),
                $this->validator->nodeCommandError($nodeFields['start'] ?? null, 'start command'),
                $this->validator->nodeOutputError($nodeFields['output'] ?? null),
                isset($nodeFields['health_path']) ? $this->validator->routePathError($nodeFields['health_path']) : null,
            ] as $err) {
                if ($err !== null) {
                    return Response::text("Error: {$err}");
                }
            }
        }

        $params += $nodeFields;
        $flagNames = [
            'node_version' => 'node-version',
            'health_path' => 'health-path',
        ];
        $args = ['app edit', escapeshellarg($name)];
        foreach ($params as $k => $v) {
            $args[] = '--' . ($flagNames[$k] ?? $k) . '=' . escapeshellarg((string) $v);
        }

        $job = $this->jobs->dispatch('app-edit', implode(' ', $args), ['app' => $name] + $params);
        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'php' => $schema->string()->description('PHP version'),
            'branch' => $schema->string()->description('Branch'),
            'repository' => $schema->string()->description('Repository URL'),
            'domain' => $schema->string()->description('New primary domain (previous primary becomes an alias; promoting an existing alias works too). Requires Cipi 4.6.2+'),
            'node' => $schema->string()->description('Node app mode: spa, static or ssr (Node apps only, Cipi 5.4.0+)'),
            'node_version' => $schema->string()->description('Node major (22, 24, …). On a Laravel app this pins the app to that major; "default" follows the server default again'),
            'build' => $schema->string()->description('Node build command (Node apps only)'),
            'start' => $schema->string()->description('Start command for ssr Node apps'),
            'output' => $schema->string()->description('Build output directory for spa/static Node apps'),
            'health_path' => $schema->string()->description('Health path for ssr Node apps'),
        ];
    }
}
