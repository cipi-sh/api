<?php

return [
    'apps_json' => env('CIPI_APPS_JSON', '/etc/cipi/apps.json'),

    /** Laravel connection name used for `GET /api/dbs` (SHOW DATABASES + size). Must be mysql/mariadb. */
    'mysql_list_connection' => env('CIPI_MYSQL_LIST_CONNECTION', 'mysql'),

    /** Schemas excluded from the database list (system databases). */
    'mysql_system_databases' => [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
    ],

    'php_versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],

    'reserved_usernames' => [
        'root', 'admin', 'www', 'nginx', 'mysql', 'mariadb', 'redis',
        'git', 'deploy', 'cipi', 'ubuntu', 'debian', 'supervisor',
        'nobody', 'postfix', 'sshd', 'clamav', 'daemon', 'bin', 'sys',
    ],
];
