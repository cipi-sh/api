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
 * restarts each Octane it moves). From Cipi 5.17.0 an Octane app has its own
 * routes: the PHP it runs (`GET /apps/{name}/octane`), that PHP by its number
 * (`PUT …/octane/php` — the build and the server's PHP together) and a
 * restart (`POST …/octane/restart`), both jobs.
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

    /** The PHP of one Octane app: what answers requests, what runs the rest (Cipi CLI ≥ 5.17.0). */
    public function appPhp(string $name): JsonResponse
    {
        if ($r = $this->notOctane($name)) {
            return $r;
        }

        try {
            return response()->json(['data' => $this->frankenphp->appPhp($name)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /** Put an Octane app on one PHP version, by its number — a job. */
    public function switchPhp(Request $request, string $name): JsonResponse
    {
        if ($r = $this->notOctane($name)) {
            return $r;
        }
        $validated = $request->validate([
            'php' => ['required', 'string', 'regex:/^[0-9]\.[0-9]$/'],
        ]);

        try {
            $command = $this->frankenphp->phpCommand($name, $validated['php']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        $job = $this->jobs->dispatch('octane-php', $command, ['app' => $name, 'php' => $validated['php']]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /** Restart Octane on its pinned build, checked — a job. */
    public function restart(string $name): JsonResponse
    {
        if ($r = $this->notOctane($name)) {
            return $r;
        }

        try {
            $command = $this->frankenphp->restartCommand($name);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        $job = $this->jobs->dispatch('octane-restart', $command, ['app' => $name]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    protected function notOctane(string $name): ?JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if ((($this->validator->getApps()[$name] ?? [])['octane'] ?? '') !== 'frankenphp') {
            return response()->json(['error' => "App '{$name}' does not run on Octane"], 422);
        }

        return null;
    }
}
