<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiDeployStatusCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Is a deploy running for an app right now (cipi deploy <app> --status)? Sees a deploy whoever started it — Git webhook, CLI, panel, rollback — with its trigger, branch, start time and elapsed seconds, plus how the last deploy ended (result, release, duration). Optional tail of the deploy log. Requires Cipi 5.8.4+.')]
#[IsReadOnly]
class DeployStatusTool extends Tool
{
    public function __construct(
        protected CipiDeployStatusCliService $status,
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

        $tail = (int) ($request->get('tail') ?? 0);
        if ($tail < 0 || $tail > CipiDeployStatusCliService::MAX_TAIL) {
            return Response::text('Error: tail must be between 0 and ' . CipiDeployStatusCliService::MAX_TAIL);
        }

        try {
            return Response::text(json_encode($this->status->show($name, $tail), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'tail' => $schema->integer()->description('Last N lines of the deploy log to include (0-500, default 0)'),
        ];
    }
}
