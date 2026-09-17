<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiSearchCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Meilisearch (Laravel Scout) status: installed, running, version, health and search-enabled apps (cipi search status). Requires Cipi 5.2.2+.')]
#[IsReadOnly]
class SearchStatusTool extends Tool
{
    public function __construct(
        protected CipiSearchCliService $search,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return Response::text(json_encode($this->search->status(), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
