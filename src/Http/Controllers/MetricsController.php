<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiMetricsService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Metrics & health history (`cipi metrics`, Cipi CLI ≥ 5.11.0). All
 * synchronous: the CLI reads a local SQLite database.
 *
 * Reading needs `metrics-view`; retention, probe, collector on/off and the
 * heartbeat need `metrics-manage`. The OpenMetrics endpoint (a port and
 * firewall rules), prune and reset stay on the host.
 */
class MetricsController extends Controller
{
    public function __construct(
        protected CipiMetricsService $metrics,
        protected CipiValidationService $validator,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->respond(fn () => $this->metrics->overview());
    }

    public function series(Request $request): JsonResponse
    {
        $input = $request->validate(CipiMetricsService::SERIES_RULES);
        if (! empty($input['app']) && ! $this->validator->appExists($input['app'])) {
            return response()->json(['error' => "App '{$input['app']}' not found"], 404);
        }
        if ($err = $this->metrics->seriesError($input)) {
            return response()->json(['error' => $err], 422);
        }

        return $this->respond(fn () => $this->metrics->series($input));
    }

    public function appSeries(Request $request, string $name): JsonResponse
    {
        if (! $this->validator->appExists($name)) {
            return response()->json(['error' => "App '{$name}' not found"], 404);
        }
        $input = $request->validate(array_diff_key(CipiMetricsService::SERIES_RULES, ['app' => true]));
        $input['app'] = $name;
        if ($err = $this->metrics->seriesError($input)) {
            return response()->json(['error' => $err], 422);
        }

        return $this->respond(fn () => $this->metrics->series($input));
    }

    public function uptime(Request $request): JsonResponse
    {
        $input = $request->validate(CipiMetricsService::UPTIME_RULES);
        if (! empty($input['app']) && ! $this->validator->appExists($input['app'])) {
            return response()->json(['error' => "App '{$input['app']}' not found"], 404);
        }

        return $this->respond(fn () => $this->metrics->uptime($input['app'] ?? null, $input['range'] ?? null));
    }

    public function events(Request $request): JsonResponse
    {
        $input = $request->validate(CipiMetricsService::EVENTS_RULES);
        if (! empty($input['app']) && ! $this->validator->appExists($input['app'])) {
            return response()->json(['error' => "App '{$input['app']}' not found"], 404);
        }

        return $this->respond(fn () => $this->metrics->events($input));
    }

    public function settings(): JsonResponse
    {
        return $this->respond(fn () => $this->metrics->status());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $input = $request->validate(CipiMetricsService::SETTINGS_RULES);
        if ($err = $this->metrics->settingsError($input)) {
            return response()->json(['error' => $err], 422);
        }

        return $this->respond(fn () => $this->metrics->updateSettings($input), 422);
    }

    public function setHeartbeat(Request $request): JsonResponse
    {
        $input = $request->validate(CipiMetricsService::HEARTBEAT_RULES);

        return $this->respond(fn () => $this->metrics->heartbeatSet($input['url'], $input['every'] ?? null), 422);
    }

    public function deleteHeartbeat(): JsonResponse
    {
        return $this->respond(fn () => $this->metrics->heartbeatOff());
    }

    public function testHeartbeat(): JsonResponse
    {
        return $this->respond(fn () => $this->metrics->heartbeatTest());
    }

    /** A CLI or version failure: 503 for reads (the data is not there), $writeStatus for a refused change. */
    protected function respond(callable $fn, int $writeStatus = 503): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()], 200);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'needs Cipi') ? 503 : $writeStatus;

            return response()->json(['error' => $e->getMessage()], $status);
        }
    }
}
