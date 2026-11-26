<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\CipiValkeyCliService;
use CipiApi\Services\ValkeyRefusedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Valkey per app, for the whole server (`cipi valkey`, Cipi CLI ≥ 5.17.0 + API sudoers ≥ 5.17.0).
 *
 * `GET` lists who uses what (`services-view`). Giving an app its own instance
 * and moving it back are jobs (`services-manage`): the keys are copied with the
 * app in maintenance mode, its workers restart. Restarting an instance and
 * emptying the shared one are synchronous and answer with the list after them.
 * No password is ever in an answer.
 */
class ValkeyController extends Controller
{
    public function __construct(
        protected CipiValkeyCliService $valkey,
        protected CipiJobService $jobs,
        protected CipiValidationService $validator,
    ) {}

    public function list(): JsonResponse
    {
        return $this->respond(fn () => $this->valkey->list());
    }

    /** `{app}` for one app, `{all: true}` for every app still on the shared instance. */
    public function isolate(Request $request): JsonResponse
    {
        $input = $request->validate([
            'app' => 'sometimes|nullable|string|max:32',
            'all' => 'sometimes|boolean',
            'copy' => 'sometimes|boolean',
        ]);
        $app = $input['app'] ?? null;
        $all = (bool) ($input['all'] ?? false);
        if (($app === null || $app === '') === ! $all) {
            return response()->json(['error' => 'Give either app (one app) or all: true (every app on the shared instance)'], 422);
        }
        if (! $all && ($r = $this->refuseApp($app, 'own'))) {
            return $r;
        }

        try {
            $command = $this->valkey->isolateCommand($all ? null : $app, (bool) ($input['copy'] ?? true));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        $job = $this->jobs->dispatch('valkey-isolate', $command, ['app' => $all ? null : $app, 'all' => $all]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function share(Request $request): JsonResponse
    {
        $input = $request->validate([
            'app' => 'required|string|max:32',
            'copy' => 'sometimes|boolean',
        ]);
        if ($r = $this->refuseApp($input['app'], 'shared')) {
            return $r;
        }

        try {
            $command = $this->valkey->shareCommand($input['app'], (bool) ($input['copy'] ?? true));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        $job = $this->jobs->dispatch('valkey-share', $command, ['app' => $input['app']]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    public function restart(string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        return $this->respond(fn () => $this->valkey->restart($name));
    }

    public function flushShared(): JsonResponse
    {
        return $this->respond(fn () => $this->valkey->flushShared(), 409);
    }

    /** 404/422 for an app that cannot be moved to $target (`own`, `shared`), or null. */
    protected function refuseApp(string $app, string $target): ?JsonResponse
    {
        if (! $this->validator->appExists($app)) {
            return response()->json(['error' => "App '{$app}' not found"], 404);
        }
        try {
            $row = $this->valkey->app($app);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        if ($row === null) {
            return response()->json(['error' => "'{$app}' is not a Laravel app: Cipi gives it no Valkey"], 422);
        }
        if (($row['uses'] ?? null) === $target) {
            return response()->json(['error' => $target === 'own'
                ? "'{$app}' has its own Valkey instance already"
                : "'{$app}' is on the shared Valkey already"], 422);
        }
        if ($target === 'shared' && ($row['uses'] ?? null) !== 'own') {
            return response()->json(['error' => "'{$app}' has no instance of its own to leave"], 422);
        }

        return null;
    }

    /** @param  callable(): array  $action */
    protected function respond(callable $action, int $refused = 422): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], 200);
        } catch (ValkeyRefusedException $e) {
            return response()->json(['error' => $e->getMessage()], $refused);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
