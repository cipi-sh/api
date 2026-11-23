<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiMetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Metrics collector settings (cipi metrics status, Cipi CLI ≥ 5.11.0): on/off, last run, database size and rows, retention (raw_days, rollup_days, resolution_minutes, events_days), healthcheck probe, heartbeat (URL by host only), OpenMetrics endpoint (no token).')]
#[IsReadOnly]
class MetricsSettingsShowTool extends Tool
{
    public function __construct(
        protected CipiMetricsService $metrics,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->metrics->status(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
