<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiAppLimitsService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Per-app resource limits and soft disk limit (`cipi app limits`, disk since Cipi CLI ≥ 5.5.0).
 * Updates run as an async job: the CLI rebuilds the FPM pool / Supervisor programs, and a
 * disk limit measures the app (files + database) before it returns.
 */
class AppLimitsController extends Controller
{
    public function __construct(
        protected CipiAppLimitsService $limits,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function show(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        try {
            return response()->json(['data' => $this->limits->show($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate(CipiAppLimitsService::RULES);
        if ($err = $this->limits->inputError($name, $validated)) {
            return response()->json(['error' => $err], 422);
        }

        $command = $this->limits->command($name, $validated);
        $job = $this->jobs->dispatch('app-limits', $command, ['app' => $name] + $validated);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }
}
