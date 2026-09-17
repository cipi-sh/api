<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiRoutesCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * App and path redirects (`cipi redirect`, Cipi CLI ≥ 5.3.1 + API sudoers ≥ 5.4.1).
 * All operations are synchronous: the CLI regenerates the vhost, runs `nginx -t`
 * and reverts on failure, so errors surface in the response.
 */
class RedirectController extends Controller
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
            return response()->json(['data' => $this->routes->redirects($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Set (or replace) the whole-app redirect: every hostname → target URL.
     */
    public function set(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'to' => 'required|string|max:2048',
            'code' => 'nullable|integer|in:301,302,307,308',
            'keep_path' => 'nullable|boolean',
        ]);

        if ($err = $this->validator->redirectUrlError($validated['to'])) {
            return response()->json(['error' => $err], 422);
        }

        try {
            $data = $this->routes->setAppRedirect(
                $name,
                $validated['to'],
                (int) ($validated['code'] ?? 301),
                (bool) ($validated['keep_path'] ?? true),
            );

            return response()->json(['data' => $data], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function enable(string $name): JsonResponse
    {
        return $this->toggle($name, true);
    }

    public function disable(string $name): JsonResponse
    {
        return $this->toggle($name, false);
    }

    public function unset(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->routes->unsetAppRedirect($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Add (or update) a path redirect. `from` ending in `/` is a prefix match.
     */
    public function add(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'from' => 'required|string|max:512',
            'to' => 'required|string|max:2048',
            'code' => 'nullable|integer|in:301,302,307,308',
            'keep_path' => 'nullable|boolean',
        ]);

        if ($err = $this->validator->routePathError($validated['from'])) {
            return response()->json(['error' => $err], 422);
        }
        if (! str_starts_with($validated['to'], '/') && ($err = $this->validator->redirectUrlError($validated['to']))) {
            return response()->json(['error' => $err], 422);
        }

        try {
            $data = $this->routes->addRedirect(
                $name,
                $validated['from'],
                $validated['to'],
                (int) ($validated['code'] ?? 301),
                (bool) ($validated['keep_path'] ?? true),
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
            'from' => 'required|string|max:512',
        ]);

        if ($err = $this->validator->routePathError($validated['from'])) {
            return response()->json(['error' => $err], 422);
        }

        try {
            return response()->json(['data' => $this->routes->removeRedirect($name, $validated['from'])], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    protected function toggle(string $name, bool $enabled): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->routes->toggleAppRedirect($name, $enabled)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
