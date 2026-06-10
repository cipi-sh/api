<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiServerStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Server status: system info, CPU, memory, disk, services, PHP pools, and app count (same data as `cipi status` and GET /api/status).')]
#[IsReadOnly]
class ServerStatusTool extends Tool
{
    public function __construct(
        protected CipiServerStatusService $status,
    ) {}

    public function handle(Request $request): Response
    {
        return Response::text(json_encode($this->status->snapshot(), JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
