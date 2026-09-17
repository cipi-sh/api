<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Mcp\Support\McpArgValidator;
use CipiApi\Services\CipiDeployAuditCliService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Deploy audit ledger for an app (cipi deploy <app> --audit): one record per deploy event (published/failed/rollback) whatever started it — CLI, panel, webhook, SSH, cron — with origin, operator, IP and claimed metadata. Requires Cipi 5.4.0+.')]
#[IsReadOnly]
class DeployAuditTool extends Tool
{
    public function __construct(
        protected CipiDeployAuditCliService $audit,
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

        $days = (int) ($request->get('days') ?? 90);
        if ($days < 1 || $days > 3650) {
            return Response::text('Error: days must be between 1 and 3650');
        }

        try {
            $records = $this->audit->show($name, $days);

            return Response::text(json_encode(['app' => $name, 'records' => $records], JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('App name')->required(),
            'days' => $schema->integer()->description('Look-back window in days (default 90)'),
        ];
    }
}
