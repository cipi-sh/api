<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiFirewallService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Firewall of the server (cipi firewall status --json): UFW state and rules (removable or kept by Cipi), the ports kept open, Zero Trust locks, fail2ban threshold, jails and banned IPs, CrowdSec engine, bouncer, allowlist and decisions. Read-only — changes go through the REST API (/api/firewall*) or the CLI. Requires Cipi 5.14.0+.')]
#[IsReadOnly]
class FirewallStatusTool extends Tool
{
    public function __construct(
        protected CipiFirewallService $firewall,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->firewall->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
