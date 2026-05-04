<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\CipiJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ActivityController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(200, $limit));

        $type = (string) $request->query('type', '');
        $app = (string) $request->query('app', '');
        $status = (string) $request->query('status', '');

        $query = CipiJob::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($app !== '') {
            $query->forApp($app);
        }
        if ($status !== '' && in_array($status, ['pending', 'running', 'completed', 'failed'], true)) {
            $query->where('status', $status);
        }

        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (is_array($decoded) && isset($decoded['id'])) {
                $cursorJob = CipiJob::find($decoded['id']);
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
        }

        $jobs = $query->limit($limit + 1)->get();
        $hasMore = $jobs->count() > $limit;
        $items = $jobs->take($limit);

        $data = $items->map(fn (CipiJob $j) => [
            'id' => $j->id,
            'type' => $this->mapEventType($j->type, $j->status),
            'job_type' => $j->type,
            'status' => $j->status,
            'subject' => [
                'type' => $this->subjectType($j->type),
                'id' => $j->appName(),
            ],
            'metadata' => array_merge(
                (array) ($j->params ?? []),
                array_filter([
                    'duration_seconds' => $j->duration_seconds,
                    'exit_code' => $j->exit_code,
                ], fn ($v) => $v !== null)
            ),
            'created_at' => $j->created_at?->toIso8601String(),
            'finished_at' => $j->finished_at?->toIso8601String(),
        ])->values();

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

    protected function mapEventType(string $type, string $status): string
    {
        $event = $type;
        if ($status === 'completed') {
            return $event . '.success';
        }
        if ($status === 'failed') {
            return $event . '.failed';
        }
        if ($status === 'running') {
            return $event . '.running';
        }
        return $event . '.pending';
    }

    protected function subjectType(string $type): string
    {
        if (str_starts_with($type, 'app')) {
            return 'app';
        }
        if (str_starts_with($type, 'alias')) {
            return 'alias';
        }
        if (str_starts_with($type, 'db')) {
            return 'database';
        }
        if (str_starts_with($type, 'ssl')) {
            return 'ssl';
        }
        return 'job';
    }
}
