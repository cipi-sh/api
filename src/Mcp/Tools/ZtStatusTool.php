<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiZtCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Cloudflare Zero Trust status (cipi zt status): cloudflared/tunnel state, real_ip, HTTP/SSH locks and hostnames. Read-only — enable, hostname, access and lock commands stay on the host CLI. Requires Cipi 5.3.0+.')]
#[IsReadOnly]
class ZtStatusTool extends Tool
{
    public function __construct(
        protected CipiZtCliService $zt,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->zt->status(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
