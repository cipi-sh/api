<?php

return [
    'apps_json' => env('CIPI_APPS_JSON', '/etc/cipi/apps.json'),

    'php_versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],

    'reserved_usernames' => [
        'root', 'admin', 'www', 'nginx', 'mysql', 'mariadb', 'redis',
        'git', 'deploy', 'cipi', 'ubuntu', 'debian', 'supervisor',
        'nobody', 'postfix', 'sshd', 'clamav', 'daemon', 'bin', 'sys',
    ],

    'cipi_binary' => env('CIPI_BINARY', '/usr/local/bin/cipi'),

    'version_file' => env('CIPI_VERSION_FILE', '/etc/cipi/version'),

    'job_logs' => [
        // Resolved by JobLogService at runtime to storage_path('app/cipi-job-logs') when null.
        'path' => env('CIPI_JOB_LOGS_PATH'),
        'retention_days' => (int) env('CIPI_JOB_LOGS_RETENTION_DAYS', 14),
    ],

    'server' => [
        'status_cache_ttl' => (int) env('CIPI_SERVER_STATUS_CACHE_TTL', 15),
        'services' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('CIPI_SERVER_SERVICES', 'nginx,php8.3-fpm,mariadb,mysql,redis,supervisor')
        )))),
    ],

    'metrics' => [
        'enabled' => (bool) env('CIPI_METRICS_ENABLED', true),
        'retention_days' => (int) env('CIPI_METRICS_RETENTION_DAYS', 30),
    ],

    'ssl' => [
        'cache_ttl' => (int) env('CIPI_SSL_CACHE_TTL', 300),
        'expiring_threshold_days' => (int) env('CIPI_SSL_EXPIRING_THRESHOLD_DAYS', 14),
        'host' => env('CIPI_SSL_HOST', '127.0.0.1'),
        'port' => (int) env('CIPI_SSL_PORT', 443),
        'connect_timeout' => (int) env('CIPI_SSL_CONNECT_TIMEOUT', 5),
    ],

    'db_backups' => [
        'path' => env('CIPI_DB_BACKUPS_PATH', '/home/cipi/backups'),
    ],

    'push' => [
        'driver' => env('CIPI_PUSH_DRIVER', 'log'),
        'fcm' => [
            'project_id' => env('CIPI_FCM_PROJECT_ID'),
            'credentials' => env('CIPI_FCM_CREDENTIALS'),
        ],
    ],
];
