<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiFrankenPhpService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('FrankenPHP builds of Octane apps (cipi frankenphp status, Cipi CLI ≥ 5.10.0): for each Octane app the pinned build, the PHP inside it and its state (ok, vulnerable, mismatch = not the app\'s PHP minor, unpinned, missing), whether a restart is pending; the builds on the server and whether nightly patch upgrades are on.')]
#[IsReadOnly]
class FrankenPhpStatusTool extends Tool
{
    public function __construct(protected CipiFrankenPhpService $frankenphp) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->frankenphp->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
