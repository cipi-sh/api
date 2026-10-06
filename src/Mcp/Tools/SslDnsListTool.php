<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiSslDnsCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('List the Cloudflare accounts configured for DNS-01 certificates and the certificates that renew with each (cipi ssl dns list; tokens are never shown). Use an account name with SslInstall. Requires Cipi CLI ≥ 5.5.0.')]
#[IsReadOnly]
class SslDnsListTool extends Tool
{
    public function __construct(
        protected CipiSslDnsCliService $dns,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->dns->accounts(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
