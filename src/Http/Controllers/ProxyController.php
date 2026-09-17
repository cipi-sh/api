<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiRoutesCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Prefix reverse proxies (`cipi proxy`, Cipi CLI ≥ 5.3.1 + API sudoers ≥ 5.4.1).
 * The CLI loopback guard is never bypassed from the API: proxies to ports Cipi
 * already uses (databases, Meilisearch, another app's Octane/Reverb, nginx) are refused.
 */
class ProxyController extends Controller
{
    public function __construct(
        protected CipiRoutesCliService $routes,
        protected CipiValidationService $validator,
    ) {}

    public function list(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->routes->proxies($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function add(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'prefix' => 'required|string|max:512',
            'upstream' => 'required|string|max:2048',
            'strip_prefix' => 'nullable|boolean',
            'preserve_host' => 'nullable|boolean',
            'timeout' => 'nullable|integer|min:1|max:3600',
            'buffering' => 'nullable|boolean',
        ]);

        if ($err = $this->validator->routePathError($validated['prefix'])) {
            return response()->json(['error' => $err], 422);
        }
        if ($err = $this->validator->proxyUpstreamError($validated['upstream'])) {
            return response()->json(['error' => $err], 422);
        }

        try {
            $data = $this->routes->addProxy(
                $name,
                $validated['prefix'],
                $validated['upstream'],
                (bool) ($validated['strip_prefix'] ?? false),
                (bool) ($validated['preserve_host'] ?? false),
                (int) ($validated['timeout'] ?? 60),
                (bool) ($validated['buffering'] ?? true),
            );

            return response()->json(['data' => $data], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function remove(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'prefix' => 'required|string|max:512',
        ]);

        if ($err = $this->validator->routePathError($validated['prefix'])) {
            return response()->json(['error' => $err], 422);
        }

        try {
            return response()->json(['data' => $this->routes->removeProxy($name, $validated['prefix'])], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
