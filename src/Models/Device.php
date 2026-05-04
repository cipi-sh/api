<?php

namespace CipiApi\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $table = 'cipi_devices';

    protected $fillable = [
        'token_id',
        'platform',
        'push_token',
        'device_name',
        'app_version',
        'os_version',
        'notification_preferences',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'notification_preferences' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function wantsNotification(string $type): bool
    {
        $prefs = $this->notification_preferences ?? [];

        if ($prefs === [] || $prefs === null) {
            return true;
        }

        if (array_key_exists($type, $prefs)) {
            return (bool) $prefs[$type];
        }

        $prefix = explode('.', $type, 2)[0];
        if (array_key_exists($prefix, $prefs)) {
            return (bool) $prefs[$prefix];
        }

        return true;
    }
}
