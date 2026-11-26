<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiFrankenPhpService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('The PHP of an Octane app (cipi frankenphp php <app>, Cipi CLI ≥ 5.17.0): octane_php is the PHP inside its FrankenPHP build (what answers requests), php the server\'s PHP for Composer, artisan, cron and workers. state is mismatch when their minors differ, and fix names the command that puts them on one. available_php lists the minors a build on this server exists for.')]
#[IsReadOnly]
class OctanePhpShowTool extends Tool
{
    public function __construct(
        protected CipiFrankenPhpService $frankenphp,
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
        if ((($this->validator->getApps()[$name] ?? [])['octane'] ?? '') !== 'frankenphp') {
            return Response::text("Error: App '{$name}' does not run on Octane");
        }

        try {
            return Response::text(json_encode($this->frankenphp->appPhp($name), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Octane app name')->required(),
        ];
    }
}
