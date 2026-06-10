<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiAppArtisanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Run an Artisan command on a Laravel app synchronously (same as `cipi app artisan`). Custom apps are not supported. tinker is blocked.')]
class AppArtisanTool extends Tool
{
    public function __construct(
        protected CipiAppArtisanService $artisan,
    ) {}

    public function handle(Request $request): Response
    {
        [$name, $error] = McpArgValidator::requiredString($request, 'name');
        if ($error !== null) {
            return $error;
        }

        [$command, $error] = McpArgValidator::requiredString($request, 'command');
        if ($error !== null) {
            return $error;
        }

        try {
            $result = $this->artisan->run($name, $command);
        } catch (\InvalidArgumentException $e) {
            return Response::text('Error: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }

        $status = $result['success'] ? 'success' : 'failed';
        $exitCode = $result['exit_code'];
        $output = $result['output'] !== '' ? $result['output'] : '(no output)';

        return Response::text("Artisan {$status} (exit code: {$exitCode})\n\n{$output}");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'command' => $schema->string()
                ->description('Artisan command and arguments (e.g. migrate:status, cache:clear, queue:retry all)')
                ->required(),
        ];
    }
}
