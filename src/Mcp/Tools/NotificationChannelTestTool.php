<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiNotificationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Send a test notification to one channel, or to every enabled channel with id "all" (Cipi CLI ≥ 5.12.0). Says when the channel refused it. A disabled channel can be tested.')]
class NotificationChannelTestTool extends Tool
{
    public function __construct(
        protected CipiNotificationsService $notifications,
    ) {}

    public function handle(Request $request): Response
    {
        $id = (string) $request->get('id', '');
        if ($id !== 'all' && ! preg_match(CipiNotificationsService::ID_PATTERN, $id)) {
            return Response::text('Error: invalid channel id');
        }
        try {
            $r = $this->notifications->test($id);

            return Response::text(($r['ok'] ? 'Sent: ' : 'Failed: ') . $r['message']);
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Channel id, or "all"')->required(),
        ];
    }
}
