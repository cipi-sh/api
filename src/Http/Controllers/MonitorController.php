<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiMonitorCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * System monitor checks (Cipi CLI ≥ 5.3.0). Read-only: check thresholds,
 * enable/disable and test alerts stay on the host CLI.
 */
class MonitorController extends Controller
{
    public function __construct(
        protected CipiMonitorCliService $monitor,
    ) {}

    public function list(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->monitor->list()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
