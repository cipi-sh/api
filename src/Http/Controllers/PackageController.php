<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiPackageCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Optional host packages catalog (Cipi CLI ≥ 5.2.2). Read-only:
 * install/remove run as root and stay on the host CLI.
 */
class PackageController extends Controller
{
    public function __construct(
        protected CipiPackageCliService $packages,
    ) {}

    public function list(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->packages->list()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }
}
