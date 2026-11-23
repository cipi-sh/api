<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiMetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Metrics now (cipi metrics overview, Cipi CLI ≥ 5.11.0): host CPU, load, memory, swap, disk, network; per app CPU (% of one core), memory MB, requests and 5xx per minute, PHP-FPM children and max_children hits, queue workers, healthcheck status and response time; a 5-minute trend of the last hour per app; uptime over 24h, 7d and 30d; the latest events (monitor, health incidents, deploys, heartbeat). Start here when asked how the server or an app is doing.')]
#[IsReadOnly]
class MetricsOverviewTool extends Tool
{
    public function __construct(
        protected CipiMetricsService $metrics,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->metrics->overview(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
