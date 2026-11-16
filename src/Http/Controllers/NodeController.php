<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiNodeCliService;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\CipiVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Node frontend apps and Node runtimes (Cipi CLI ≥ 5.4.0 + API sudoers ≥ 5.4.1).
 * Installing, upgrading and removing runtimes and changing the server default
 * need the sudoers of Cipi 5.9.0: on an older core those routes answer 503
 * instead of queueing a job sudo would refuse.
 */
class NodeController extends Controller
{
    /** The core whose panel sudoers allow `cipi node install|default|upgrade|remove`. */
    public const RUNTIMES_MIN_CIPI = '5.9.0';

    public function __construct(
        protected CipiNodeCliService $node,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
        protected CipiVersionService $version,
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

    /**
     * Install a Node major (`cipi node install <major>`): the official build
     * from nodejs.org, checksum-verified, next to the ones already there.
     * Async job.
     */
    public function install(Request $request): JsonResponse
    {
        return $this->runtimeJob($request, 'node-install', 'node install', false);
    }

    /**
     * Latest patch of one major, or of every installed one when `major` is
     * omitted (`cipi node upgrade [major] [--restart]`). With `restart` the
     * SSR apps on it move to the new binary blue/green, with no downtime;
     * without it they take it at their next deploy. Async job.
     */
    public function upgrade(Request $request): JsonResponse
    {
        if ($gate = $this->runtimesGate()) {
            return $gate;
        }
        $validated = $request->validate([
            'major' => 'nullable|string',
            'restart' => 'nullable|boolean',
        ]);
        $major = isset($validated['major']) && $validated['major'] !== '' ? (string) $validated['major'] : null;
        if ($major !== null && ($err = $this->validator->nodeVersionError($major))) {
            return response()->json(['error' => $err], 422);
        }

        $command = 'node upgrade';
        $params = [];
        if ($major !== null) {
            $command .= ' ' . escapeshellarg($major);
            $params['major'] = $major;
        }
        if (! empty($validated['restart'])) {
            $command .= ' --restart';
            $params['restart'] = true;
        }
        $job = $this->jobs->dispatch('node-upgrade', $command, $params);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * The server-wide Node (`cipi node default <major>|system`): what Laravel
     * asset builds and `cipi app run npm` use. Node apps and apps pinned to a
     * major keep theirs. A major that is not installed is installed first.
     * Async job.
     */
    public function setDefault(Request $request): JsonResponse
    {
        return $this->runtimeJob($request, 'node-default', 'node default', true);
    }

    /**
     * Remove a Node major (`cipi node remove <major>`). The CLI refuses one
     * that an app uses or that is the server default; the job then fails with
     * its message. Async job.
     */
    public function remove(Request $request, string $major): JsonResponse
    {
        if ($gate = $this->runtimesGate()) {
            return $gate;
        }
        if ($err = $this->validator->nodeVersionError($major)) {
            return response()->json(['error' => $err], 422);
        }
        $job = $this->jobs->dispatch('node-remove', 'node remove ' . escapeshellarg($major), ['major' => $major]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    protected function runtimeJob(Request $request, string $type, string $command, bool $allowSystem): JsonResponse
    {
        if ($gate = $this->runtimesGate()) {
            return $gate;
        }
        $validated = $request->validate(['major' => 'required|string']);
        $major = (string) $validated['major'];
        if (! ($allowSystem && $major === 'system') && ($err = $this->validator->nodeVersionError($major))) {
            return response()->json(['error' => $err], 422);
        }
        $job = $this->jobs->dispatch($type, $command . ' ' . escapeshellarg($major), ['major' => $major]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    protected function runtimesGate(): ?JsonResponse
    {
        try {
            $this->version->require(self::RUNTIMES_MIN_CIPI, 'Managing Node runtimes from the panel');
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return null;
    }
}
