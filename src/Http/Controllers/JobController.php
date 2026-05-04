<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiOutputParser;
use CipiApi\Services\JobLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JobController extends Controller
{
    public function __construct(
        protected CipiOutputParser $parser,
        protected JobLogService $logs,
    ) {}

    public function show(string $id): JsonResponse
    {
        $job = CipiJob::find($id);
        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $data = [
            'id' => $job->id,
            'type' => $job->type,
            'app' => $job->app,
            'status' => $job->status,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'duration_seconds' => $job->duration_seconds,
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];

        if (in_array($job->status, ['completed', 'failed'])) {
            $data['exit_code'] = $job->exit_code;
            $data['result'] = $this->parser->parse(
                $job->type,
                $job->output ?? '',
                $job->status === 'completed',
            );
            $data['output'] = $job->output;
        }

        return response()->json(['data' => $data], 200);
    }

    public function logTail(Request $request, string $id): JsonResponse
    {
        $job = CipiJob::find($id);
        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $fromByte = (int) $request->query('from_byte', 0);
        $maxBytes = (int) $request->query('max_bytes', 65_536);
        $maxBytes = max(1024, min(1_048_576, $maxBytes));

        $tail = $this->logs->tail($job->id, max(0, $fromByte), $maxBytes);

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
                'exit_code' => $job->exit_code,
                'log_size' => $tail['size'],
                'next_byte' => $tail['next_byte'],
                'eof' => $tail['next_byte'] >= $tail['size'] && in_array($job->status, ['completed', 'failed'], true),
                'chunk' => $tail['chunk'],
            ],
        ], 200);
    }
}
