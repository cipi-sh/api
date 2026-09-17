<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiMonitorCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('System monitor checks with thresholds, current state and last alert (cipi monitor list): disk, ssl, services, workers, http_5xx, fs, load. Read-only — thresholds and enable/disable stay on the host CLI. Requires Cipi 5.3.0+.')]
#[IsReadOnly]
class MonitorListTool extends Tool
{
    public function __construct(
        protected CipiMonitorCliService $monitor,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->monitor->list(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
