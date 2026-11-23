<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiMetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Change metrics settings (Cipi CLI ≥ 5.11.0): retention (raw_days 1–90, rollup_days up to 400 and not shorter than raw_days, resolution_minutes 5/10/15/30/60, events_days 7–730), the per-minute healthcheck probe, the collector on/off, and the heartbeat: heartbeat_url (https://…, called every heartbeat_every minutes, 1–60, so an outside service alerts when the server stops) or heartbeat_off. A shorter retention deletes older data at the next hourly prune. Synchronous; returns the new settings.')]
class MetricsSettingsUpdateTool extends Tool
{
    public function __construct(
        protected CipiMetricsService $metrics,
    ) {}

    public function handle(Request $request): Response
    {
        $all = array_filter($request->all(['enabled', 'probe', 'raw_days', 'rollup_days', 'resolution_minutes', 'events_days', 'heartbeat_url', 'heartbeat_every', 'heartbeat_off']), fn ($v) => $v !== null && $v !== '');
        $hb = array_intersect_key($all, array_flip(['heartbeat_url', 'heartbeat_every', 'heartbeat_off']));
        $settings = array_diff_key($all, $hb);

        $v = Validator::make($settings, CipiMetricsService::SETTINGS_RULES);
        if ($v->fails()) {
            return Response::text('Error: ' . $v->errors()->first());
        }
        $settings = $v->validated();
        if (! empty($hb['heartbeat_url'])) {
            $hv = Validator::make(['url' => $hb['heartbeat_url'], 'every' => $hb['heartbeat_every'] ?? 1], CipiMetricsService::HEARTBEAT_RULES);
            if ($hv->fails()) {
                return Response::text('Error: heartbeat: ' . $hv->errors()->first());
            }
        }
        if ($settings === [] && $hb === []) {
            return Response::text('Error: nothing to change');
        }
        if ($settings !== [] && ($err = $this->metrics->settingsError($settings))) {
            return Response::text("Error: {$err}");
        }

        try {
            $out = $settings !== [] ? $this->metrics->updateSettings($settings) : null;
            if (! empty($hb['heartbeat_url'])) {
                $this->metrics->heartbeatSet($hb['heartbeat_url'], (int) ($hb['heartbeat_every'] ?? 1));
            } elseif (! empty($hb['heartbeat_off'])) {
                $this->metrics->heartbeatOff();
            }

            return Response::text(json_encode($hb !== [] || $out === null ? $this->metrics->status() : $out, JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enabled' => $schema->boolean()->description('Collector on (true) or off (false); the history is kept'),
            'probe' => $schema->boolean()->description('Probe each app\'s healthcheck URL every minute'),
            'raw_days' => $schema->integer()->description('Per-minute samples kept, days (1–90, default 7)'),
            'rollup_days' => $schema->integer()->description('Rollups kept, days (default 30, max 400)'),
            'resolution_minutes' => $schema->integer()->description('Rollup resolution: 5, 10, 15, 30 or 60 (default 15)'),
            'events_days' => $schema->integer()->description('Events kept, days (7–730, default 90)'),
            'heartbeat_url' => $schema->string()->description('URL to call every heartbeat_every minutes (healthchecks.io, Uptime Kuma push, …)'),
            'heartbeat_every' => $schema->integer()->description('Minutes between heartbeat calls, 1–60 (default 1)'),
            'heartbeat_off' => $schema->boolean()->description('true to stop the heartbeat'),
        ];
    }
}
