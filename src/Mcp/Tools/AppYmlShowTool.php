<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiValidationService;
use CipiApi\Services\CipiYmlCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Show an app\'s cipi.yml and what applying it would change (cipi yml plan --json): file (null when the live release has none), env (the app\'s environment label; Cipi 5.8.0+ reads cipi-<env>.yml before cipi.yml), auto (applied after every successful deploy), valid + errors/warnings, changes [{kind, summary}], blockers (apply refuses while any is listed), notes, in_sync. Read-only. Requires Cipi 5.6.4+.')]
#[IsReadOnly]
class AppYmlShowTool extends Tool
{
    public function __construct(
        protected CipiYmlCliService $yml,
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

        try {
            return Response::text(json_encode($this->yml->plan($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
        ];
    }
}
