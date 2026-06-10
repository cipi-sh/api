<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiServerMonitorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Show server status: CPU, memory, disk, uptime, services, PHP versions, and apps (same as `cipi status`).')]
#[IsReadOnly]
class ServerStatusTool extends Tool
{
    public function __construct(
        protected CipiServerMonitorService $monitor,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text($this->monitor->status());
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
