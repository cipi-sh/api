<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiBasicAuthCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Disable HTTP Basic Auth on an app and restore the normal Nginx vhost. Synchronous.')]
class AppBasicAuthDisableTool extends Tool
{
    public function __construct(
        protected CipiBasicAuthCliService $basicAuth,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $name = $request->get('name');
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if (! $this->validator->isBasicAuthEnabled($name)) {
            return Response::text("Error: Basic auth is not enabled for app '{$name}'");
        }

        try {
            $this->basicAuth->disable($name);

            return Response::text("Basic auth disabled for '{$name}'.");
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
        ];
    }
}
