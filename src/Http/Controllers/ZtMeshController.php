<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiZtMeshService;
use CipiApi\Services\ZtRefusedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The mesh network of Zero Trust — Tailscale or NetBird — from the panel
 * (Cipi CLI ≥ 5.18.0, ability `zt-manage`): join and leave a network, close
 * and reopen the SSH port and 80/443 to the world, Tailscale SSH on and off.
 *
 * Synchronous: a join carries a key, which must not reach the jobs table.
 * Every change answers with Zero Trust as it is after it (`data`, the same
 * as `GET /api/zt`) and what the CLI said (`message`); its refusal is a 422
 * with its words, an older core a 503 before anything runs.
 *
 * What a click must not do is refused by the CLI itself (it ignores --force
 * from the panel): closing the SSH port before anyone has signed in over the
 * mesh, or while the node key can expire; closing 80/443, or leaving the
 * network, when that is the way this very request reached the server; and
 * closing 80/443 when a panel that runs on this server says the person at it
 * (`client`, their browser's address) is on the public address — the page
 * would close itself.
 *
 * `zt-manage` is as strong as `ssh-manage`: whoever holds it can put the
 * server on a network of their own. A token made with a list of abilities
 * has it only once it is named there; a full-access token has every ability.
 * Cloudflare's side stays read-only (`GET /api/zt`).
 */
class ZtMeshController extends Controller
{
    public function __construct(
        protected CipiZtMeshService $mesh,
    ) {}

    /**
     * Join a network. `provider` tailscale|netbird; `key` (an auth key, a
     * setup key — left out to take a client that is already connected as it
     * is); `hostname`; `control_url` (Headscale, a self-hosted NetBird);
     * `direct`, `accept_dns`, `accept_routes`; for Tailscale `tags`, `ssh`,
     * `reauth`.
     */
    public function join(Request $request): JsonResponse
    {
        $v = $request->validate([
            'provider' => 'required|string|in:tailscale,netbird',
            'key' => 'nullable|string|max:512',
            'hostname' => 'nullable|string|max:63',
            'control_url' => 'nullable|string|max:255',
            'tags' => 'nullable',
            'direct' => 'sometimes|boolean',
            'accept_dns' => 'sometimes|boolean',
            'accept_routes' => 'sometimes|boolean',
            'ssh' => 'sometimes|boolean',
            'reauth' => 'sometimes|boolean',
        ]);
        $error = CipiZtMeshService::joinError($v);
        if ($error !== null) {
            return response()->json(['message' => $error, 'error' => $error], 422);
        }

        return $this->change($request, fn () => $this->mesh->join($v));
    }

    /** Leave the network: what its locks closed is reopened first, then the client goes. */
    public function leave(Request $request): JsonResponse
    {
        return $this->change($request, fn () => $this->mesh->leave($this->mesh->clientIp($request->ip())));
    }

    /**
     * Close `port` (ssh | http) to the world: open on the mesh only. `client`
     * is for a panel that runs on this server: the address of the person at
     * it, so that 80/443 are not closed under their own page.
     */
    public function lock(Request $request): JsonResponse
    {
        $v = $request->validate(['port' => 'required|string|in:ssh,http', 'client' => 'nullable|ip']);

        return $this->change($request, fn () => $this->mesh->lock($v['port'], $this->mesh->clientIp($request->ip()), $this->mesh->clientIp($v['client'] ?? null)));
    }

    /** Reopen `port` (ssh | http) to the world. */
    public function unlock(Request $request): JsonResponse
    {
        $v = $request->validate(['port' => 'required|string|in:ssh,http']);

        return $this->change($request, fn () => $this->mesh->unlock($v['port']));
    }

    /** Tailscale SSH on or off: `enabled`. */
    public function ssh(Request $request): JsonResponse
    {
        $v = $request->validate(['enabled' => 'required|boolean']);

        return $this->change($request, fn () => $this->mesh->providerSsh((bool) $v['enabled']));
    }

    /** A change, then Zero Trust as it is: `{data, message}`; the CLI's refusal is a 422 with its words. */
    protected function change(Request $request, callable $run): JsonResponse
    {
        try {
            $message = $run();
        } catch (ZtRefusedException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        try {
            $client = $request->input('client');
            $data = $this->mesh->status($this->mesh->clientIp($request->ip()), $this->mesh->clientIp(is_string($client) ? $client : null));
        } catch (\RuntimeException) {
            $data = null;
        }

        return response()->json(['data' => $data, 'message' => $message], 200);
    }
}
