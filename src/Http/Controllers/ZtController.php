<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiZtCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Zero Trust status (Cipi CLI ≥ 5.3.0): Cloudflare Tunnel and, from Cipi
 * 5.18.0, the mesh network (Tailscale, NetBird). Read-only by design: the
 * API sudoers allow `zt status` only.
 */
class ZtController extends Controller
{
    public function __construct(
        protected CipiZtCliService $zt,
    ) {}

    public function status(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->zt->status()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
