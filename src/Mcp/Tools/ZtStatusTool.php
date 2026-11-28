<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiZtCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Zero Trust status (cipi zt status). Cloudflare: cloudflared/tunnel state, real_ip, HTTP/SSH locks and hostnames; with Cipi 5.16.0+ `details` adds Access paths and people, service tokens, TCP services, SSH certificates, previews and the connectors of the tunnel. Mesh network (Cipi 5.18.0+): `mesh` (tailscale, netbird or null), `mesh_state`, `mesh_ip`, and `details.mesh` with the address and name of the server on the network, peers, the node key expiry, its SSH/HTTP locks and warnings. Read-only: Cloudflare is changed on the host CLI; the mesh is joined, left and locked on the host CLI or with the REST API (/api/zt/mesh, ability zt-manage) — not from MCP. Requires Cipi 5.3.0+.')]
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
