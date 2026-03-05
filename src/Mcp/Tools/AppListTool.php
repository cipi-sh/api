<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('List all Cipi apps. Returns app names, domains, PHP versions, and aliases.')]
#[IsReadOnly]
class AppListTool extends Tool
{
    public function __construct(
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $request->user()?->tokenCan('apps-view')) {
            return Response::text('Permission denied: apps-view required');
        }
        $apps = $this->validator->getApps();
        $data = [];
        foreach ($apps as $name => $app) {
            $data[] = [
                'app' => $name,
                'domain' => $app['domain'] ?? '',
                'php' => $app['php'] ?? '',
                'branch' => $app['branch'] ?? '',
                'aliases' => $app['aliases'] ?? [],
            ];
        }
        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
