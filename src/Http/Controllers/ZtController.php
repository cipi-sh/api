<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiIpWhitelistService;
use CipiApi\Services\CipiZtCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Zero Trust status (Cipi CLI ≥ 5.3.0): Cloudflare Tunnel and, from Cipi
 * 5.18.0, the mesh network (Tailscale, NetBird). Cloudflare's side is
 * read-only by design; the mesh is changed by {@see ZtMeshController}.
 *
 * The address of the caller goes to the CLI: the mesh then says what the
 * panel may do from where it is (`details.mesh.panel`).
 */
class ZtController extends Controller
{
    public function __construct(
        protected CipiZtCliService $zt,
        protected CipiIpWhitelistService $ips,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $client = $this->ips->normalizeClientIp((string) $request->ip());
        $client = filter_var($client, FILTER_VALIDATE_IP) !== false ? $client : null;

        try {
            return response()->json(['data' => $this->zt->status($client)], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
