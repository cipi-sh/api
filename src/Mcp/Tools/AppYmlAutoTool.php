<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\CipiYmlCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Turn automatic cipi.yml apply after every successful deploy on or off (cipi yml auto on|off). When on, anyone who can commit to the repository can change the app\'s aliases, PHP settings, limits, workers, deploy recipe, crons, databases and backups — confirm with the user first. Synchronous, idempotent; returns {app, env, auto, sudoers, file}. Requires Cipi 5.6.4+.')]
class AppYmlAutoTool extends Tool
{
    public function __construct(
        protected CipiYmlCliService $yml,
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

        $enabled = $request->get('enabled');
        if (! is_bool($enabled)) {
            return Response::text('Error: enabled (boolean) is required');
        }

        try {
            return Response::text(json_encode($this->yml->setAuto($name, $enabled), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'enabled' => $schema->boolean()->description('true = apply cipi.yml after every successful deploy, false = deploys ignore it')->required(),
        ];
    }
}
