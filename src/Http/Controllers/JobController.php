<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiJobAbilities;
use CipiApi\Services\CipiJobStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JobController extends Controller
{
    public function __construct(
        protected CipiJobStatusService $jobStatus,
    ) {}

    /**
     * The route carries no ability: middleware because the ability depends on
     * the job — the one of the request that queued it ({@see CipiJobAbilities}).
     * A token without it gets the same 404 as an unknown id.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $job = CipiJob::find($id);
        if (! $job || ! CipiJobAbilities::canRead($request->user(), $job)) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json([
            'data' => $this->jobStatus->format($job, true),
        ], 200);
    }
}
