<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiBasicAuthCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Show HTTP Basic Auth status for an app (enabled/disabled and usernames). Synchronous.')]
class AppBasicAuthStatusTool extends Tool
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

        try {
            $data = $this->basicAuth->status($name);
            $users = implode(', ', $data['users'] ?? []) ?: '(none)';
            $status = ($data['enabled'] ?? false) ? 'enabled' : 'disabled';

            return Response::text("Basic auth for '{$name}': {$status}. Users: {$users}");
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
