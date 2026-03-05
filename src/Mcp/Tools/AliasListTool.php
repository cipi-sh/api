<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('List aliases for an app. Returns array of domain aliases.')]
#[IsReadOnly]
class AliasListTool extends Tool
{
    public function __construct(
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('aliases-view')) {
            return Response::text('Permission denied: aliases-view required');
        }
        $name = $request->get('name');
        if (! $this->validator->appExists($name)) {
            return Response::text("Error: App '{$name}' not found");
        }
        $aliases = $this->validator->getAppAliases($name);
        return Response::text(json_encode($aliases, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
        ];
    }
}
