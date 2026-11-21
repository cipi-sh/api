<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiFrankenPhpService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * FrankenPHP builds of Octane apps (`cipi frankenphp`, Cipi CLI ≥ 5.10.0).
 * GET is sync; an upgrade is an async job (it may download a release and
 * restarts each Octane it moves).
 */
class FrankenPhpController extends Controller
{
    public function __construct(
        protected CipiFrankenPhpService $frankenphp,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function status(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->frankenphp->status()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function upgrade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app' => 'sometimes|nullable|string|max:32',
        ]);
        $app = $validated['app'] ?? null;
        if ($app !== null) {
            if (! $this->validator->appExists($app)) {
                return response()->json(['error' => "App '{$app}' not found"], 404);
            }
            $row = $this->validator->getApps()[$app] ?? [];
            if (($row['octane'] ?? '') !== 'frankenphp') {
                return response()->json(['error' => "App '{$app}' does not run on Octane"], 422);
            }
        }

        try {
            $command = $this->frankenphp->upgradeCommand($app);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $job = $this->jobs->dispatch('frankenphp-upgrade', $command, ['app' => $app]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }
}
