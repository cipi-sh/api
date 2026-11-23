<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiNotificationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Turn notification triggers on or off (Cipi CLI ≥ 5.12.0): on and off are lists of trigger ids (deploy_fail, ssh_login, monitor_disk, backup_fail, …; NotificationsShowTool with triggers: true lists them), all: true|false switches every trigger, reset: true turns them all back on. Events are logged on the server whatever is muted.')]
class NotificationTriggersUpdateTool extends Tool
{
    public function __construct(
        protected CipiNotificationsService $notifications,
    ) {}

    public function handle(Request $request): Response
    {
        $input = array_filter($request->all(['on', 'off', 'all']), fn ($v) => $v !== null && $v !== []);
        foreach (['on', 'off'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = array_values(array_filter(array_map('trim', explode(',', $input[$k]))));
            }
        }
        try {
            if ($request->get('reset')) {
                $out = $this->notifications->resetTriggers();
            } else {
                $v = Validator::make($input, CipiNotificationsService::TRIGGERS_RULES);
                if ($v->fails()) {
                    return Response::text('Error: ' . $v->errors()->first());
                }
                $input = $v->validated();
                if ($err = $this->notifications->triggersError($input)) {
                    return Response::text("Error: {$err}");
                }
                $out = $this->notifications->updateTriggers($input);
            }
            $off = array_values(array_map(fn ($t) => $t['id'], array_filter($out['triggers'] ?? [], fn ($t) => empty($t['enabled']))));

            return Response::text("Triggers on: {$out['enabled']}/{$out['total']}" . ($off !== [] ? "\nOff: " . implode(', ', $off) : ''));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'on' => $schema->string()->description('Trigger ids to turn on, comma-separated (deploy_fail,backup_fail)'),
            'off' => $schema->string()->description('Trigger ids to turn off, comma-separated'),
            'all' => $schema->boolean()->description('true: every trigger on; false: every trigger off'),
            'reset' => $schema->boolean()->description('Every trigger back on (the default)'),
        ];
    }
}
