<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiAppJitService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Show PHP JIT for an app (cipi app jit, Cipi CLI ≥ 5.10.0): runtime (fpm|octane), the PHP that answers (for Octane the one inside its FrankenPHP build), enabled, mode, buffer, supported and a note when it cannot be turned on.')]
#[IsReadOnly]
class AppJitShowTool extends Tool
{
    public function __construct(
        protected CipiAppJitService $jit,
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
            return Response::text(json_encode($this->jit->show($name), JSON_PRETTY_PRINT));
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
