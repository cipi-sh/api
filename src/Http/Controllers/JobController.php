<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiJobStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class JobController extends Controller
{
    public function __construct(
        protected CipiJobStatusService $jobStatus,
    ) {}

    public function show(string $id): JsonResponse
    {
        $job = CipiJob::find($id);
        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json([
            'data' => $this->jobStatus->format($job, true),
        ], 200);
    }
}
