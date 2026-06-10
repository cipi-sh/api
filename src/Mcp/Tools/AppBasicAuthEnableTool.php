<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiBasicAuthCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Enable HTTP Basic Auth on an app (Nginx gate). Optional user/password; password is auto-generated when omitted. Synchronous.')]
class AppBasicAuthEnableTool extends Tool
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

        $user = $request->get('user');
        $password = $request->get('password');
        if (is_string($user)) {
            $user = trim($user);
            if ($user === '') {
                $user = null;
            }
        }
        if ($user !== null && ! preg_match('/^[A-Za-z0-9._-]+$/', $user)) {
            return Response::text('Error: Invalid username. Use letters, digits, dot, underscore, hyphen.');
        }

        try {
            $data = $this->basicAuth->enable($name, $user, $password);
            $userOut = $data['user'] ?? $user ?? 'admin';
            $lines = ["Basic auth enabled for '{$name}'.", "User: {$userOut}"];
            if (! empty($data['password'])) {
                $lines[] = "Password: {$data['password']}";
                $lines[] = 'Save this password — shown only once.';
            }

            return Response::text(implode("\n", $lines));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'user' => $schema->string()->description('HTTP basic auth username (default: admin)'),
            'password' => $schema->string()->description('Password (auto-generated when omitted)'),
        ];
    }
}
