<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiSearchCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Meilisearch for Laravel Scout (Cipi CLI ≥ 5.2.2). Panel-safe subset only:
 * status + per-app enable/disable. install / upgrade / key rotate / remove
 * stay with the operator on the host CLI.
 */
class SearchController extends Controller
{
    public function __construct(
        protected CipiSearchCliService $search,
        protected CipiValidationService $validator,
    ) {}

    public function status(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->search->status()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function enable(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if ($this->validator->isCustomApp($name) || $this->validator->isNodeApp($name)) {
            return response()->json(['error' => 'Search (Laravel Scout) is only available for Laravel apps'], 422);
        }

        try {
            $this->search->enable($name);

            return response()->json(['data' => ['app' => $name, 'enabled' => true]], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function disable(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            $this->search->disable($name);

            return response()->json(['data' => ['app' => $name, 'enabled' => false]], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
