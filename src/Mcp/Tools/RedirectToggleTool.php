<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiRoutesCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Enable or disable the saved whole-app redirect without forgetting its target (cipi redirect enable|disable). Requires Cipi CLI ≥ 5.4.1.')]
class RedirectToggleTool extends Tool
{
    public function __construct(
        protected CipiRoutesCliService $routes,
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
            return Response::text(json_encode($this->routes->toggleAppRedirect($name, $enabled), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'enabled' => $schema->boolean()->description('true = enable the saved redirect, false = serve the app again (target kept)')->required(),
        ];
    }
}
