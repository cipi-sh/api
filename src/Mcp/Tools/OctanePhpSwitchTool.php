<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiFrankenPhpService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Put an Octane app on a PHP version, by its number (cipi frankenphp php <app> X.Y, Cipi CLI ≥ 5.17.0): the FrankenPHP build that carries PHP X.Y (fetched when the server has none), Octane restarted and checked — the previous build back if it does not answer — then Composer, artisan, cron and workers. PHP X.Y must be installed on the server. Dispatches an async job; poll JobShow.')]
class OctanePhpSwitchTool extends Tool
{
    public function __construct(
        protected CipiFrankenPhpService $frankenphp,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }
        [$php, $error] = McpArgValidator::requiredString($request, 'php');
        if ($error !== null) {
            return $error;
        }
        if (preg_match('/^[0-9]\.[0-9]$/', $php) !== 1) {
            return Response::text('Error: php must be a version such as 8.5');
        }
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ((($this->validator->getApps()[$name] ?? [])['octane'] ?? '') !== 'frankenphp') {
            return Response::text("Error: App '{$name}' does not run on Octane");
        }

        try {
            $command = $this->frankenphp->phpCommand($name, $php);
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
        $job = $this->jobs->dispatch('octane-php', $command, ['app' => $name, 'php' => $php]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Octane app name')->required(),
            'php' => $schema->string()->description('The PHP minor, e.g. 8.5')->required(),
        ];
    }
}
