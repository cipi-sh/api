<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiFrankenPhpService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Upgrade the FrankenPHP build of one Octane app, or of all of them without name (cipi frankenphp upgrade, Cipi CLI ≥ 5.10.0): the newest build on the app\'s own PHP minor and FrankenPHP major. Octane restarts, is checked, and goes back to the previous build if it does not answer. Never moves an app to another PHP minor. Dispatches an async job; poll JobShow.')]
class FrankenPhpUpgradeTool extends Tool
{
    public function __construct(
        protected CipiFrankenPhpService $frankenphp,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $name = $request->get('name');
        if ($name !== null && (! is_string($name) || $name === '')) {
            return Response::text('Error: name must be an app name');
        }
        if ($name !== null) {
            if (! $this->validator->appExists($name)) {
                return Response::text("Error: App '{$name}' not found");
            }
            if ((($this->validator->getApps()[$name] ?? [])['octane'] ?? '') !== 'frankenphp') {
                return Response::text("Error: App '{$name}' does not run on Octane");
            }
        }

        try {
            $command = $this->frankenphp->upgradeCommand($name);
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
        $job = $this->jobs->dispatch('frankenphp-upgrade', $command, ['app' => $name]);

        return Response::text("Job dispatched: {$job->id} (status: pending). Poll JobShow with id {$job->id} for result.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Octane app name; omit for every Octane app'),
        ];
    }
}
