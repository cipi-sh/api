<?php

namespace CipiApi\Notifications;

use CipiApi\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Default push driver. Writes payloads to the Laravel log instead of sending
 * remote notifications, so installs without FCM credentials still observe the
 * full notification dispatch flow during development.
 */
class LogPushDriver implements PushDriverContract
{
    public function send(Device $device, array $payload): bool
    {
        Log::info('cipi.push', [
            'device_id' => $device->id,
            'platform' => $device->platform,
            'token_id' => $device->token_id,
            'payload' => $payload,
        ]);
        return true;
    }
}
