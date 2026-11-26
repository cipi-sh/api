<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiValkeyCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Valkey per app (cipi valkey list, Cipi CLI ≥ 5.17.0): every Laravel app with the Valkey its .env points at — own (an instance of its own, with port, memory and keys), shared (the server\'s instance, whose password every app on it holds), external or none — and `shared`, the apps still on the shared instance. No password is returned. Read-only: giving an app its own instance is POST /api/valkey/isolate or cipi valkey isolate.')]
#[IsReadOnly]
class ValkeyListTool extends Tool
{
    public function __construct(
        protected CipiValkeyCliService $valkey,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->valkey->list(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
