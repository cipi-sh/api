<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiNotificationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Remove a notification channel (Cipi CLI ≥ 5.12.0). "smtp" removes the SMTP configuration. To pause a channel instead, use NotificationChannelSaveTool with enabled: false.')]
class NotificationChannelRemoveTool extends Tool
{
    public function __construct(
        protected CipiNotificationsService $notifications,
    ) {}

    public function handle(Request $request): Response
    {
        $id = (string) $request->get('id', '');
        if (! preg_match(CipiNotificationsService::ID_PATTERN, $id)) {
            return Response::text('Error: invalid channel id');
        }
        try {
            if ($this->notifications->channel($id) === null) {
                return Response::text("Error: channel '{$id}' not found");
            }
            $this->notifications->remove($id);

            return Response::text("Removed '{$id}'");
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Channel id')->required(),
        ];
    }
}
