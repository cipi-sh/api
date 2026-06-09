<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiBasicAuthCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BasicAuthController extends Controller
{
    public function __construct(
        protected CipiBasicAuthCliService $basicAuth,
        protected CipiValidationService $validator,
    ) {}

    public function status(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->basicAuth->status($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function enable(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'user' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:256',
        ]);

        $user = isset($validated['user']) ? trim($validated['user']) : null;
        if ($user === '') {
            $user = null;
        }
        $password = $validated['password'] ?? null;

        if ($user !== null && ! preg_match('/^[A-Za-z0-9._-]+$/', $user)) {
            return response()->json(['error' => 'Invalid username. Use letters, digits, dot, underscore, hyphen.'], 422);
        }

        try {
            $data = $this->basicAuth->enable($name, $user, $password);
            $response = [
                'app' => $name,
                'enabled' => true,
                'user' => $data['user'] ?? $user ?? 'admin',
                'users' => $data['users'] ?? array_filter([$data['user'] ?? $user ?? 'admin']),
            ];
            if (! empty($data['password'])) {
                $response['password'] = $data['password'];
            }

            return response()->json(['data' => $response], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function disable(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if (! $this->validator->isBasicAuthEnabled($name)) {
            return response()->json(['error' => "Basic auth is not enabled for app '{$name}'"], 409);
        }

        try {
            $this->basicAuth->disable($name);

            return response()->json(['data' => ['app' => $name, 'enabled' => false]], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
