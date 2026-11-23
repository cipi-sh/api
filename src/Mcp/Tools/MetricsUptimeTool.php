<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiMetricsService;
use CipiApi\Services\CipiValidationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tool;

#[Description('Healthcheck uptime (cipi metrics uptime, Cipi CLI ≥ 5.11.0): the share of the per-minute probes of the app\'s healthcheck URL that answered the expected status, minutes down, average response time, incidents (opened at the third failure in a row, closed at the first success) and uptime per day. One app or every app with a healthcheck (cipi health set).')]
#[IsReadOnly]
class MetricsUptimeTool extends Tool
{
    public function __construct(
        protected CipiMetricsService $metrics,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $input = array_filter($request->all(['app', 'range']), fn ($v) => $v !== null && $v !== '');
        $v = Validator::make($input, CipiMetricsService::UPTIME_RULES);
        if ($v->fails()) {
            return Response::text('Error: ' . $v->errors()->first());
        }
        $input = $v->validated();
        if (! empty($input['app']) && ! $this->validator->appExists($input['app'])) {
            return Response::text("Error: App '{$input['app']}' not found");
        }

        try {
            return Response::text(json_encode($this->metrics->uptime($input['app'] ?? null, $input['range'] ?? null), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('App name; omit for every app with a healthcheck'),
            'range' => $schema->string()->description('Window: 24h, 7d, 30d (default 30d)'),
        ];
    }
}
