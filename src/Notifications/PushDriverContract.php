<?php

namespace CipiApi\Notifications;

use CipiApi\Models\Device;

interface PushDriverContract
{
    /**
     * Send a push payload to a single device. Implementations must be idempotent
     * for retries: returning false signals a permanent failure (caller may delete
     * the device), true on success or transient error.
     *
     * @param array{title: string, body: string, data?: array<string,mixed>} $payload
     */
    public function send(Device $device, array $payload): bool;
}
