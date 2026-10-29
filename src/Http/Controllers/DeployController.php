<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiDeployAuditCliService;
use CipiApi\Services\CipiDeployStatusCliService;
use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeployController extends Controller
{
    public function __construct(
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
        protected CipiDeployAuditCliService $audit,
        protected CipiDeployStatusCliService $status,
    ) {}

    public function deploy(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'deploy ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('app-deploy', $command, ['app' => $name]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function rollback(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'deploy ' . escapeshellarg($name) . ' --rollback';
        $job = $this->jobs->dispatch('app-deploy-rollback', $command, ['app' => $name]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function unlock(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $command = 'deploy ' . escapeshellarg($name) . ' --unlock';
        $job = $this->jobs->dispatch('app-deploy-unlock', $command, ['app' => $name]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * Deploy audit ledger records for an app (Cipi CLI ≥ 5.4.0). Synchronous.
     * One record per deploy event (published / failed / rollback), whatever
     * started it — CLI, panel, webhook, SSH, cron.
     */
    public function audit(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            $records = $this->audit->show($name, (int) ($validated['days'] ?? 90));

            return response()->json(['data' => ['app' => $name, 'records' => $records]], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Is a deploy running for this app right now (Cipi CLI ≥ 5.8.4)? Synchronous.
     * Reads the deploy log's banners and the live processes, so it sees a deploy
     * whoever started it — Git webhook, CLI, panel, rollback — and how the last
     * one ended. `tail` adds the last N lines of the deploy log (max 500).
     *
     * 503 on an older core: the flag is never sent to a Cipi that does not know
     * it, because there `cipi deploy <app>` would start a deploy.
     */
    public function status(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $validated = $request->validate([
            'tail' => 'nullable|integer|min:0|max:' . CipiDeployStatusCliService::MAX_TAIL,
        ]);

        try {
            $data = $this->status->show($name, (int) ($validated['tail'] ?? 0));

            return response()->json(['data' => $data], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
