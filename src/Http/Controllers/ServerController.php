<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Models\ServerMetric;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\ServerStatusService;
use CipiApi\Services\SslInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ServerController extends Controller
{
    public function __construct(
        protected ServerStatusService $status,
        protected SslInspectorService $ssl,
        protected CipiValidationService $validator,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->status->status()], 200);
    }

    public function metrics(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', '24h');
        $allowed = ['1h', '6h', '24h', '7d', '30d'];
        if (! in_array($range, $allowed, true)) {
            return response()->json(['error' => 'Invalid range. Allowed: ' . implode(', ', $allowed)], 422);
        }
        $minutes = match ($range) {
            '1h' => 60,
            '6h' => 360,
            '24h' => 1440,
            '7d' => 1440 * 7,
            '30d' => 1440 * 30,
        };

        $since = now()->subMinutes($minutes);

        $metrics = ServerMetric::query()
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get()
            ->map(function (ServerMetric $m) {
                return [
                    'recorded_at' => $m->recorded_at?->toIso8601String(),
                    'load_1m' => $m->load_1m,
                    'load_5m' => $m->load_5m,
                    'load_15m' => $m->load_15m,
                    'cpu_usage_percent' => $m->cpu_usage_percent,
                    'memory_total_mb' => $m->memory_total_mb,
                    'memory_used_mb' => $m->memory_used_mb,
                    'memory_usage_percent' => $m->memory_usage_percent,
                    'swap_total_mb' => $m->swap_total_mb,
                    'swap_used_mb' => $m->swap_used_mb,
                    'disk_root_usage_percent' => $m->disk_root_usage_percent,
                ];
            });

        return response()->json([
            'data' => $metrics,
            'range' => $range,
            'since' => $since->toIso8601String(),
            'count' => $metrics->count(),
        ], 200);
    }

    public function sslExpiring(Request $request): JsonResponse
    {
        $threshold = (int) $request->query('days', config('cipi.ssl.expiring_threshold_days', 14));
        if ($threshold < 1) {
            $threshold = 14;
        }
        if ($threshold > 365) {
            $threshold = 365;
        }

        $apps = $this->validator->getApps();
        $expiring = [];

        foreach ($apps as $appName => $app) {
            $domains = [];
            $primary = (string) ($app['domain'] ?? '');
            if ($primary !== '') {
                $domains[] = $primary;
            }
            foreach ((array) ($app['aliases'] ?? []) as $alias) {
                if ($alias) {
                    $domains[] = (string) $alias;
                }
            }

            foreach (array_unique($domains) as $domain) {
                $info = $this->ssl->inspect($domain);
                if (! $info || empty($info['available'])) {
                    continue;
                }
                if (! is_int($info['days_remaining'] ?? null)) {
                    continue;
                }
                if ($info['days_remaining'] <= $threshold) {
                    $expiring[] = [
                        'app' => $appName,
                        'domain' => $domain,
                        'issuer' => $info['issuer'] ?? null,
                        'valid_until' => $info['valid_until'] ?? null,
                        'days_remaining' => $info['days_remaining'],
                        'expired' => $info['expired'] ?? false,
                    ];
                }
            }
        }

        usort($expiring, fn ($a, $b) => ($a['days_remaining'] ?? PHP_INT_MAX) <=> ($b['days_remaining'] ?? PHP_INT_MAX));

        return response()->json([
            'data' => $expiring,
            'threshold_days' => $threshold,
            'count' => count($expiring),
        ], 200);
    }
}
