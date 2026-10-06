<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiAppLimitsService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update app resource limits (cipi app limits): PHP-FPM max children, memory_limit, Octane workers, queue worker processes, and the soft disk limit in GB (Cipi CLI ≥ 5.5.0; remove_disk_limit drops it). The disk limit only raises monitor alerts — nothing is blocked. Dispatches an async job; poll JobShow.')]
class AppLimitsUpdateTool extends Tool
{
    public function __construct(
        protected CipiAppLimitsService $limits,
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

        $input = array_filter(
            $request->all(['fpm_max_children', 'memory_limit', 'octane_workers', 'worker_procs', 'disk_limit_gb']),
            fn ($v) => $v !== null,
        );
        if ($request->get('remove_disk_limit') === true) {
            if (array_key_exists('disk_limit_gb', $input)) {
                return Response::text('Error: disk_limit_gb and remove_disk_limit exclude each other');
            }
            $input['disk_limit_gb'] = null;
        }

        $validator = Validator::make($input, CipiAppLimitsService::RULES);
        if ($validator->fails()) {
            return Response::text('Error: ' . $validator->errors()->first());
        }
        $validated = $validator->validated();
        if ($err = $this->limits->inputError($name, $validated)) {
            return Response::text("Error: {$err}");
        }

        $command = $this->limits->command($name, $validated);
        $job = $this->jobs->dispatch('app-limits', $command, ['app' => $name] + $validated);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'fpm_max_children' => $schema->integer()->description('PHP-FPM pm.max_children (1-50, default 5)'),
            'memory_limit' => $schema->string()->description('PHP memory_limit, e.g. 256M or 1G'),
            'octane_workers' => $schema->integer()->description('Octane workers (1-16, default 2; Octane apps)'),
            'worker_procs' => $schema->integer()->description('Default queue worker processes (1-20, default 1)'),
            'disk_limit_gb' => $schema->number()->description('Soft disk limit in GB for files + database (e.g. 10 or 2.5, max two decimals)'),
            'remove_disk_limit' => $schema->boolean()->description('Remove the soft disk limit'),
        ];
    }
}
