<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class JobController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $job = CipiJob::find($id);
        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $data = [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];

        if (in_array($job->status, ['completed', 'failed'])) {
            $data['output'] = $job->output;
            $data['exit_code'] = $job->exit_code;
        }

        return response()->json(['data' => $data], 200);
    }
}
