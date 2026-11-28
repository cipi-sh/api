<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiJobService;
use CipiApi\Services\CipiSyncService;
use CipiApi\Services\SyncUnsupportedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Moving apps to another server (`cipi sync`, Cipi ≥ 5.18.0). Ability
 * `sync-manage` on every route.
 *
 * The server that has the apps (the source) pushes; the one that gets them
 * (the target) must trust the source's key first:
 *
 *   source  GET  /api/sync/key      → its public key
 *   target  POST /api/sync/trust    ← that key
 *   source  POST /api/sync/check    → does the target accept it, does Cipi answer
 *   source  POST /api/sync/push     → a job: export, send, import on the target
 *   target  DELETE /api/sync/trust  ← the key, when the trust is not to be kept
 *
 * The archive is encrypted with a passphrase the source makes for that one
 * transfer and hands to the target's import over SSH. No route takes, returns
 * or queues a passphrase.
 *
 * `sync-manage` is as strong as `ssh-manage`: whoever holds it can have this
 * server send every app — code, .env, databases — to a host they choose, and
 * can let another server's `cipi` user sign in here.
 */
class SyncController extends Controller
{
    public function __construct(
        protected CipiSyncService $sync,
        protected CipiJobService $jobs,
    ) {}

    public function key(): JsonResponse
    {
        return $this->answer(fn () => ['data' => $this->sync->key()]);
    }

    public function check(Request $request): JsonResponse
    {
        $v = $this->target($request);

        return $this->answer(fn () => ['data' => $this->sync->check($v['host'], (int) ($v['port'] ?? 22), $v['user'] ?? 'cipi')]);
    }

    public function trust(Request $request): JsonResponse
    {
        $v = $request->validate(['key' => 'required|string|max:2048']);

        return $this->answer(fn () => ['data' => $this->sync->trust($v['key']), 'message' => 'The key is trusted: its server can push apps here.']);
    }

    public function untrust(Request $request): JsonResponse
    {
        $v = $request->validate(['key' => 'required|string|max:2048']);

        return $this->answer(fn () => ['data' => $this->sync->untrust($v['key']), 'message' => 'The key is no longer trusted here.']);
    }

    /**
     * Export the apps, send them, import them on the target. `apps` left out
     * or empty: every app of this server. Apps that exist on the target are
     * updated, the others created.
     */
    public function push(Request $request): JsonResponse
    {
        $v = $this->target($request) + $request->validate([
            'apps' => 'nullable|array|max:500',
            'apps.*' => 'string|max:32',
            'with_db' => 'sometimes|boolean',
            'with_storage' => 'sometimes|boolean',
        ]);
        $apps = array_values($v['apps'] ?? []);
        $port = (int) ($v['port'] ?? 22);
        $user = $v['user'] ?? 'cipi';
        $withDb = (bool) ($v['with_db'] ?? false);
        $withStorage = (bool) ($v['with_storage'] ?? false);

        return $this->answer(function () use ($v, $apps, $port, $user, $withDb, $withStorage) {
            $command = $this->sync->pushCommand($v['host'], $port, $user, $apps, $withDb, $withStorage);
            $job = $this->jobs->dispatch('sync-push', $command, [
                'host' => $v['host'], 'port' => $port, 'user' => $user, 'apps' => $apps, 'with_db' => $withDb, 'with_storage' => $withStorage,
            ]);

            return ['job_id' => $job->id, 'status' => 'pending'];
        }, 202);
    }

    /**
     * @return array{host: string, port?: int, user?: string}
     */
    protected function target(Request $request): array
    {
        return $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'user' => 'nullable|string|max:32',
        ]);
    }

    /**
     * @param  callable(): array<string, mixed>  $make
     */
    protected function answer(callable $make, int $status = 200): JsonResponse
    {
        try {
            return response()->json($make(), $status);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->getMessage()], 422);
        } catch (SyncUnsupportedException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->getMessage()], 503);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->getMessage()], 422);
        }
    }
}
