<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiSearchCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Enable Meilisearch for a Laravel app (cipi search enable): mints a scoped API key and writes SCOUT_* / MEILISEARCH_* into shared/.env. Meilisearch must already be installed on the host (cipi search install). Requires Cipi 5.2.2+.')]
class SearchEnableTool extends Tool
{
    public function __construct(
        protected CipiSearchCliService $search,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }

        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        if ($this->validator->isCustomApp($name) || $this->validator->isNodeApp($name)) {
            return Response::text('Error: Search (Laravel Scout) is only available for Laravel apps');
        }

        try {
            $this->search->enable($name);

            return Response::text(json_encode(['app' => $name, 'enabled' => true], JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name (Laravel app)')->required(),
        ];
    }
}
