<?php

namespace CipiApi\Mcp\Tools;

use CipiApi\Services\CipiNotificationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a notification channel, or change one that exists (Cipi CLI ≥ 5.12.0). Fields per type — smtp: host, port, user, password, from, to, tls; resend: api_key (re_…), from, to; ses: region, access_key, secret_key, from, to; slack/discord/mattermost/googlechat: url (incoming webhook, https); telegram: token, chat_id; ntfy: url, priority; pushover: token, user; webhook: url, secret (clear_secret: true to drop it). to: one or more addresses, comma-separated. On a change, fields left out keep their value — secrets too. enabled turns it on/off; test: true sends a test after saving.')]
class NotificationChannelSaveTool extends Tool
{
    public function __construct(
        protected CipiNotificationsService $notifications,
    ) {}

    public function handle(Request $request): Response
    {
        $keys = array_keys(CipiNotificationsService::CHANNEL_RULES);
        $input = array_filter($request->all($keys), fn ($v) => $v !== null && $v !== '');
        $v = Validator::make($input, CipiNotificationsService::CHANNEL_RULES);
        if ($v->fails()) {
            return Response::text('Error: ' . $v->errors()->first());
        }
        $input = $v->validated();
        $id = ($input['type'] ?? null) === 'smtp' ? 'smtp' : ($input['id'] ?? null);
        if ($id === null) {
            return Response::text('Error: id is required (and type, for a new channel)');
        }

        try {
            $current = $this->notifications->channel($id);
            if ($err = $this->notifications->channelError($current === null ? $input : array_diff_key($input, ['id' => 1]), $current)) {
                return Response::text("Error: {$err}");
            }
            if ($current === null) {
                $out = $this->notifications->add($input);
            } else {
                $out = $this->notifications->update($id, $current['type'], array_diff_key($input, ['id' => 1, 'type' => 1]));
            }

            return Response::text(($current === null ? "Added '{$id}'" : "Updated '{$id}'") . "\n" . json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\RuntimeException $e) {
            return Response::text('Error: ' . $e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('smtp, resend, ses, slack, discord, mattermost, googlechat, telegram, ntfy, pushover, webhook (required to add)'),
            'id' => $schema->string()->description('Channel id: lowercase letters, digits, dashes (e.g. ops). SMTP is always "smtp"'),
            'host' => $schema->string()->description('smtp: server host'),
            'port' => $schema->integer()->description('smtp: port (587)'),
            'user' => $schema->string()->description('smtp: user; pushover: user key'),
            'password' => $schema->string()->description('smtp: password'),
            'from' => $schema->string()->description('smtp/resend/ses: sender'),
            'to' => $schema->string()->description('smtp/resend/ses: recipients, comma-separated'),
            'tls' => $schema->boolean()->description('smtp: TLS (default true)'),
            'url' => $schema->string()->description('slack/discord/mattermost/googlechat/ntfy/webhook: URL'),
            'priority' => $schema->string()->description('ntfy: min, low, default, high, urgent'),
            'secret' => $schema->string()->description('webhook: signing secret (X-Cipi-Signature)'),
            'clear_secret' => $schema->boolean()->description('webhook: drop the signing secret'),
            'token' => $schema->string()->description('telegram: bot token; pushover: application token'),
            'chat_id' => $schema->string()->description('telegram: chat id (negative for groups)'),
            'api_key' => $schema->string()->description('resend: API key (re_…)'),
            'region' => $schema->string()->description('ses: AWS region'),
            'access_key' => $schema->string()->description('ses: access key id'),
            'secret_key' => $schema->string()->description('ses: secret access key'),
            'enabled' => $schema->boolean()->description('Turn the channel on or off'),
            'test' => $schema->boolean()->description('Send a test after saving'),
        ];
    }
}
