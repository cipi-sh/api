<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiPageCacheService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('The nginx page cache of an app, or of every app without name (cipi cache, Cipi CLI ≥ 5.17.0): on or off, how long pages are kept (ttl), the size on disk, pages cached now, whether it is emptied after a deploy, and clear_command — what a script or a custom deploy recipe runs to empty it. The page cache is not OPcache (always on, compiled PHP in memory): with it nginx answers a kept page without running PHP at all.')]
#[IsReadOnly]
class PageCacheShowTool extends Tool
{
    public function __construct(
        protected CipiPageCacheService $cache,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $name = $request->get('name');
        if ($name !== null && (! is_string($name) || trim($name) === '')) {
            return Response::text('Error: name must be an app name');
        }
        if ($name !== null && ! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }

        try {
            return Response::text(json_encode($name === null ? $this->cache->list() : $this->cache->show($name), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name; omit for every app'),
        ];
    }
}
