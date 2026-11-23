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

#[Description('Timeline of what changed (cipi metrics events, Cipi CLI ≥ 5.11.0), newest first: monitor checks changing state (disk, ssl, services, workers, http_5xx, fs, load), healthcheck incidents opening and closing, every release put live with its post-deploy verdict, heartbeat calls failing and working again. Filter by app (subject), source and range.')]
#[IsReadOnly]
class MetricsEventsTool extends Tool
{
    public function __construct(
        protected CipiMetricsService $metrics,
        protected CipiValidationService $validator,
    ) {}

    public function handle(Request $request): Response
    {
        $input = array_filter($request->all(['app', 'source', 'range', 'limit']), fn ($v) => $v !== null && $v !== '');
        $v = Validator::make($input, CipiMetricsService::EVENTS_RULES);
        if ($v->fails()) {
            return Response::text('Error: ' . $v->errors()->first());
        }
        $input = $v->validated();
        if (! empty($input['app']) && ! $this->validator->appExists($input['app'])) {
            return Response::text("Error: App '{$input['app']}' not found");
        }

        try {
            return Response::text(json_encode($this->metrics->events($input), JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('App name (events whose subject is the app)'),
            'source' => $schema->string()->enum(CipiMetricsService::SOURCES)->description('monitor, health, deploy or heartbeat'),
            'range' => $schema->string()->description('How far back: 24h, 7d, 30d (default 7d)'),
            'limit' => $schema->integer()->description('At most this many (1–1000, default 100)'),
        ];
    }
}
