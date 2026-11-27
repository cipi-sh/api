<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiPageCacheService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Purge some pages of an app\'s nginx page cache (cipi cache <app> purge, Cipi CLI ≥ 5.18.0), leaving the rest warm. A page is a path (/blog/post/: every domain of the app, http and https, with its ?utm variants), a prefix (/blog/*) or a URL on one of the app\'s domains. At most 500. Answers with `purged`, the cached files deleted.')]
class PageCachePurgeTool extends Tool
{
    public function __construct(
        protected CipiPageCacheService $cache,
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
        $paths = $request->get('paths');
        if (! is_array($paths)) {
            return Response::text('Error: paths must be a list of pages (/blog/post/, /blog/*)');
        }

        try {
            return Response::text(json_encode($this->cache->purge($name, array_values($paths)), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'paths' => $schema->array()->items($schema->string())->description('Pages to purge: /blog/post/, /category/news/* (every page under it), https://www.example.com/x/')->required(),
        ];
    }
}
