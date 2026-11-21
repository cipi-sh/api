<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiAppJitService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Turn PHP JIT on or off for an app (cipi app jit, Cipi CLI ≥ 5.10.0). The CLI first checks that the app\'s PHP starts with JIT, then restarts its PHP-FPM (every app on that PHP version) or its Octane, and puts the previous setting back if the app does not come up. Dispatches an async job; poll JobShow.')]
class AppJitUpdateTool extends Tool
{
    public function __construct(
        protected CipiAppJitService $jit,
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

        $input = array_filter($request->all(['enabled', 'mode', 'buffer']), fn ($v) => $v !== null);
        $validator = Validator::make($input, CipiAppJitService::RULES);
        if ($validator->fails()) {
            return Response::text('Error: ' . $validator->errors()->first());
        }
        $validated = $validator->validated();
        if ($err = $this->jit->inputError($name, $validated)) {
            return Response::text("Error: {$err}");
        }

        $job = $this->jobs->dispatch('app-jit', $this->jit->command($name, $validated), ['app' => $name] + $validated);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'enabled' => $schema->boolean()->description('true = on, false = off')->required(),
            'mode' => $schema->string()->enum(CipiAppJitService::MODES)->description('JIT mode (default tracing)'),
            'buffer' => $schema->string()->description('JIT buffer, 16M to 1G (default 128M). On PHP-FPM it is shared by every app on that PHP version with JIT: the largest is used'),
        ];
    }
}
