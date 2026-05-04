<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use CipiApi\Services\CipiOutputParser;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeployHistoryController extends Controller
{
    public const DEPLOY_TYPES = ['app-deploy', 'app-deploy-rollback', 'app-deploy-unlock'];

    public function __construct(
        protected CipiValidationService $validator,
        protected CipiOutputParser $parser,
    ) {}

    public function list(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $limit = (int) $request->query('limit', 20);
        $limit = max(1, min(100, $limit));

        $cursor = $request->query('cursor');
        $beforeId = null;
        if (is_string($cursor) && $cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (is_array($decoded) && isset($decoded['id'])) {
                $beforeId = (string) $decoded['id'];
            }
        }

        $query = CipiJob::query()
            ->forApp($name)
            ->ofTypes(self::DEPLOY_TYPES)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($beforeId) {
            $cursorJob = CipiJob::find($beforeId);
            if ($cursorJob) {
                $query->where(function ($q) use ($cursorJob) {
                    $q->where('created_at', '<', $cursorJob->created_at)
                        ->orWhere(function ($q2) use ($cursorJob) {
                            $q2->where('created_at', '=', $cursorJob->created_at)
                                ->where('id', '<', $cursorJob->id);
                        });
                });
            }
        }

        $jobs = $query->limit($limit + 1)->get();

        $hasMore = $jobs->count() > $limit;
        $items = $jobs->take($limit);

        $data = $items->map(fn (CipiJob $j) => $this->present($j))->values();

        $nextCursor = null;
        if ($hasMore) {
            $last = $items->last();
            if ($last) {
                $nextCursor = base64_encode(json_encode(['id' => $last->id]));
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'count' => $data->count(),
                'limit' => $limit,
                'next_cursor' => $nextCursor,
            ],
        ], 200);
    }

    public function show(string $name, string $jobId): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $job = CipiJob::query()
            ->forApp($name)
            ->ofTypes(self::DEPLOY_TYPES)
            ->where('id', $jobId)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Deploy not found'], 404);
        }

        $payload = $this->present($job);
        if (in_array($job->status, ['completed', 'failed'])) {
            $payload['result'] = $this->parser->parse($job->type, $job->output ?? '', $job->status === 'completed');
        }

        return response()->json(['data' => $payload], 200);
    }

    public function log(string $name, string $jobId): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }

        $job = CipiJob::query()
            ->forApp($name)
            ->ofTypes(self::DEPLOY_TYPES)
            ->where('id', $jobId)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Deploy not found'], 404);
        }

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
                'exit_code' => $job->exit_code,
                'output' => $job->output,
            ],
        ], 200);
    }

    protected function present(CipiJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'app' => $job->appName(),
            'status' => $job->status,
            'exit_code' => $job->exit_code,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'duration_seconds' => $job->duration_seconds,
            'triggered_by' => $job->triggered_by,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
