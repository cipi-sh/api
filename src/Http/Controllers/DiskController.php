<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiDiskCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Disk usage (Cipi CLI ≥ 5.5.0, API sudoers ≥ 5.5.2). Read-only: the
 * figures of `cipi disk` and `cipi disk db`, measured when requested.
 * The soft limit per app is set through PUT /api/apps/{name}/limits.
 */
class DiskController extends Controller
{
    public function __construct(
        protected CipiDiskCliService $disk,
    ) {}

    /** The filesystem /home is on, then every app largest first. */
    public function usage(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->disk->usage()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /** Every database of every installed engine, in MB. */
    public function databases(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->disk->databases()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
