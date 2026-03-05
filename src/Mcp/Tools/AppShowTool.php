<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Show details of a specific app. Returns domain, PHP version, branch, aliases, etc.')]
#[IsReadOnly]
class AppShowTool extends Tool
{
    public function __construct(
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('apps-view')) {
            return Response::text('Permission denied: apps-view required');
        }
        $name = $request->get('name');
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        $apps = $this->validator->getApps();
        $app = $apps[$name];
        $app['app'] = $name;
        return Response::text(json_encode($app, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
        ];
    }
}
