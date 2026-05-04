<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PingController extends Controller
{
    public function __construct(protected CipiVersionService $version) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'cipi' => true,
            'version' => $this->version->version(),
            'time' => now()->toIso8601String(),
        ], 200);
    }
}
