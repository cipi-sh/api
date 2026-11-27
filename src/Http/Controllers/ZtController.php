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
 * panel may do from where it is (`details.mesh.panel`). `?client=<address>`
 * is for a panel that runs on this server: the address of the person at it,
 * so that the answer also says whether closing 80/443 would close their page.
 */
class ZtController extends Controller
{
    public function __construct(
        protected CipiZtCliService $zt,
        protected CipiIpWhitelistService $ips,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $ip = fn (mixed $v): ?string => is_string($v) && filter_var($v = $this->ips->normalizeClientIp($v), FILTER_VALIDATE_IP) !== false ? $v : null;

        try {
            return response()->json(['data' => $this->zt->status($ip($request->ip()), $ip($request->query('client')))], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
