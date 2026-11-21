<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiAppJitService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * PHP JIT per app (`cipi app jit`, Cipi CLI ≥ 5.10.0). Reading is sync; turning
 * it on or off is an async job: it restarts the app's PHP-FPM (every app on
 * that PHP version) or its Octane, and the CLI puts the previous setting back
 * when the app does not come up.
 */
class AppJitController extends Controller
{
    public function __construct(
        protected CipiAppJitService $jit,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function show(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->jit->show($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate(CipiAppJitService::RULES);
        if ($err = $this->jit->inputError($name, $validated)) {
            return response()->json(['error' => $err], 422);
        }

        $job = $this->jobs->dispatch('app-jit', $this->jit->command($name, $validated), ['app' => $name] + $validated);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }
}
