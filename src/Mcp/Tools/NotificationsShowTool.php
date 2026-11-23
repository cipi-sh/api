<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiNotificationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Notification channels and triggers of the server (Cipi CLI ≥ 5.12.0): every channel — smtp, resend, ses, slack, discord, mattermost, googlechat, telegram, ntfy, pushover, webhook — with its state and target (secrets are never returned, only *_set), and how many triggers are on. With triggers: true, every trigger (id, category, label, enabled). Every enabled trigger reaches every enabled channel.')]
class NotificationsShowTool extends Tool
{
    public function __construct(
        protected CipiNotificationsService $notifications,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $out = $this->notifications->status();
            if ($request->get('triggers')) {
                $out['triggers'] = $this->notifications->triggers();
            }

            return Response::text(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'triggers' => $schema->boolean()->description('Also list every trigger with its state'),
        ];
    }
}
