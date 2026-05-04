<?php

namespace CipiApi\Notifications;

use CipiApi\Models\Device;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    public function __construct(protected PushDriverContract $driver) {}

    /**
     * Dispatch a push notification to every device whose preferences accept
     * the given event type. The optional $tokenId scope lets callers fan out
     * to a single API token's devices.
     *
     * @param array{title: string, body: string, data?: array<string,mixed>} $payload
     */
    public function fanout(string $type, array $payload, ?int $tokenId = null): int
    {
        $payload['data'] = array_merge($payload['data'] ?? [], ['type' => $type]);

        $query = Device::query();
        if ($tokenId !== null) {
            $query->where('token_id', $tokenId);
        }

        $sent = 0;
        $query->orderBy('id')->each(function (Device $device) use ($type, $payload, &$sent) {
            if (! $device->wantsNotification($type)) {
                return;
            }

            try {
                if ($this->driver->send($device, $payload)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('cipi.push.failure', [
                    'device_id' => $device->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $sent;
    }
}
