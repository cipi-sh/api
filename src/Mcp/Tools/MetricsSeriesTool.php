<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiMetricsService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('A metric over time (cipi metrics show, Cipi CLI ≥ 5.11.0), for the host or one app: about 240 points of [timestamp, average, maximum]. Within the raw retention (7 days by default) from the per-minute samples, beyond it from the rollups (15-minute average and maximum, 30 days by default). Host metrics: cpu, load1, mem, mem_used_mb, swap, disk, net_rx, net_tx. App metrics: cpu, mem_mb, procs, fpm_procs, fpm_max, fpm_saturated, req, http_5xx, http_ms, http_ok, http_code, workers_up, workers_total. Use it to find when something started (a memory leak, a 5xx spike, saturation) and correlate with MetricsEvents (deploys).')]
#[IsReadOnly]
class MetricsSeriesTool extends Tool
{
    public function __construct(
        protected CipiMetricsService $metrics,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $input = array_filter($request->all(['app', 'metrics', 'range', 'from', 'to']), fn ($v) => $v !== null && $v !== '');
        $v = Validator::make($input, CipiMetricsService::SERIES_RULES);
        if ($v->fails()) {
            return Response::text('Error: ' . $v->errors()->first());
        }
        $input = $v->validated();
        if (! empty($input['app']) && ! $this->validator->appExists($input['app'])) {
            return Response::text("Error: App '{$input['app']}' not found");
        }
        if ($err = $this->metrics->seriesError($input)) {
            return Response::text("Error: {$err}");
        }

        try {
            return Response::text(json_encode($this->metrics->series($input)));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('App name; omit for the host'),
            'metrics' => $schema->string()->description('Comma-separated metric names (default: all of the scope), e.g. "cpu,mem_mb,http_5xx"'),
            'range' => $schema->string()->description('How far back: 90m, 24h, 7d, 30d (default 24h; capped at the rollup retention)'),
            'from' => $schema->integer()->description('Start, Unix timestamp (instead of range)'),
            'to' => $schema->integer()->description('End, Unix timestamp (with from; default now)'),
        ];
    }
}
