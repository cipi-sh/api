<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiServerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class StatusController extends Controller
{
    public function __construct(
        protected CipiServerStatusService $status,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->status->snapshot()], 200);
    }
}
