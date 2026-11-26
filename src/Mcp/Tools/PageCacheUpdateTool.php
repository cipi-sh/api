<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiPageCacheService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Turn the nginx page cache of an app on or off (cipi cache <app> on|off, Cipi CLI ≥ 5.17.0). On: nginx keeps the pages PHP made and answers the next visitors without running PHP — what makes WordPress and content sites fast; it respects the app\'s Cache-Control and Set-Cookie and skips signed-in visitors, carts, admin and API paths. ttl, size and clear_on_deploy apply with enabled true; what is not given stays. nginx is tested first: a refusal changes nothing. Synchronous.')]
class PageCacheUpdateTool extends Tool
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

        $input = array_filter($request->all(['enabled', 'ttl', 'size', 'clear_on_deploy']), fn ($v) => $v !== null);
        $validator = Validator::make($input, CipiPageCacheService::RULES);
        if ($validator->fails()) {
            return Response::text('Error: ' . $validator->errors()->first());
        }
        $validated = $validator->validated();
        if ($err = $this->cache->inputError($validated)) {
            return Response::text("Error: {$err}");
        }

        try {
            return Response::text(json_encode($this->cache->update($name, $validated), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'enabled' => $schema->boolean()->description('true = on, false = off (the cached pages are deleted)')->required(),
            'ttl' => $schema->string()->description('How long a page is kept when the app does not say: a number and s, m, h or d (default 10m)'),
            'size' => $schema->string()->description('The most the cache takes on disk, 16m to 100g (default 256m)'),
            'clear_on_deploy' => $schema->boolean()->description('Empty the cache after every deploy and rollback (default true)'),
        ];
    }
}
