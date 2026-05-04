<?php

namespace CipiApi\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMetric extends Model
{
    protected $table = 'cipi_server_metrics';

    protected $fillable = [
        'recorded_at',
        'load_1m',
        'load_5m',
        'load_15m',
        'cpu_usage_percent',
        'memory_total_mb',
        'memory_used_mb',
        'memory_usage_percent',
        'swap_total_mb',
        'swap_used_mb',
        'disk_root_usage_percent',
        'disks',
        'services',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'load_1m' => 'float',
            'load_5m' => 'float',
            'load_15m' => 'float',
            'cpu_usage_percent' => 'float',
            'memory_total_mb' => 'integer',
            'memory_used_mb' => 'integer',
            'memory_usage_percent' => 'float',
            'swap_total_mb' => 'integer',
            'swap_used_mb' => 'integer',
            'disk_root_usage_percent' => 'float',
            'disks' => 'array',
            'services' => 'array',
        ];
    }
}
