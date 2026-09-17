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

#[Description('Remove a path redirect from an app (cipi redirect remove). Requires Cipi CLI ≥ 5.4.1.')]
class RedirectRemoveTool extends Tool
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
        [$from, $error] = McpArgValidator::requiredString($request, 'from');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($err = $this->validator->routePathError($from)) {
            return Response::text("Error: {$err}");
        }

        try {
            return Response::text(json_encode($this->routes->removeRedirect($name, $from), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'from' => $schema->string()->description('Source path of the redirect to remove')->required(),
        ];
    }
}
