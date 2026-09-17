<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiNodeCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Node frontend apps and Node runtimes (Cipi CLI ≥ 5.4.0 + API sudoers ≥ 5.4.1).
 * Installing/removing runtimes and changing the server default stay on the host CLI.
 */
class NodeController extends Controller
{
    public function __construct(
        protected CipiNodeCliService $node,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    /**
     * Installed Node runtimes (`cipi node list`).
     */
    public function runtimes(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->node->runtimes()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Node status for an app (mode, framework, version, build/start/output).
     */
    public function status(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        return response()->json(['data' => $this->node->status($name)], 200);
    }

    /**
     * Blue/green restart of an SSR Node app (no downtime). Async job.
     */
    public function restart(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        if (! $this->validator->isNodeApp($name)) {
            return response()->json(['error' => "App '{$name}' is not a Node app"], 409);
        }

        $command = 'node restart ' . escapeshellarg($name);
        $job = $this->jobs->dispatch('node-restart', $command, ['app' => $name]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }
}
