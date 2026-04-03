<?php

namespace CipiApi\Services;

use CipiApi\Exceptions\MysqlDatabaseListingUnavailableException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lists MySQL/MariaDB databases using the Laravel DB connection (same pattern as reading {@see CipiValidationService::getApps} from disk).
 */
class CipiMysqlDatabaseListService
{
    /**
     * @return list<array{name: string, size: string}>
     */
    public function list(): array
    {
        $connection = config('cipi.mysql_list_connection', 'mysql');
        $driver = config("database.connections.{$connection}.driver");

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new MysqlDatabaseListingUnavailableException(
                "Cannot list databases: connection \"{$connection}\" must use driver mysql or mariadb (got " . ($driver ?? 'null') . '). Set CIPI_MYSQL_LIST_CONNECTION to a MySQL connection.'
            );
        }

        try {
            $rows = DB::connection($connection)->select('SHOW DATABASES');
        } catch (Throwable $e) {
            throw new MysqlDatabaseListingUnavailableException(
                'Cannot list MySQL databases: ' . $e->getMessage(),
                $e
            );
        }

        $system = config('cipi.mysql_system_databases', [
            'information_schema',
            'mysql',
            'performance_schema',
            'sys',
        ]);

        $names = [];
        foreach ($rows as $row) {
            $name = $this->extractDatabaseName($row);
            if ($name === null || $name === '') {
                continue;
            }
            if (in_array($name, $system, true)) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);

        $sizes = $this->fetchSizesMb($connection, $names);

        $out = [];
        foreach ($names as $name) {
            $mb = $sizes[$name] ?? 0.0;
            $out[] = [
                'name' => $name,
                'size' => $this->formatSizeMb((float) $mb),
            ];
        }

        return $out;
    }

    protected function extractDatabaseName(object $row): ?string
    {
        $vars = get_object_vars($row);
        if (isset($vars['Database'])) {
            return (string) $vars['Database'];
        }

        $values = array_values($vars);

        return isset($values[0]) ? (string) $values[0] : null;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, float>
     */
    protected function fetchSizesMb(string $connection, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = <<<SQL
            SELECT table_schema AS db_name,
                   ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables
            WHERE table_schema IN ({$placeholders})
            GROUP BY table_schema
            SQL;

        try {
            $rows = DB::connection($connection)->select($sql, $names);
        } catch (Throwable) {
            return array_fill_keys($names, 0.0);
        }

        $sizes = [];
        foreach ($rows as $row) {
            $vars = get_object_vars($row);
            $db = $vars['db_name'] ?? null;
            if ($db === null) {
                continue;
            }
            $sizes[(string) $db] = (float) ($vars['size_mb'] ?? 0.0);
        }

        foreach ($names as $name) {
            if (! array_key_exists($name, $sizes)) {
                $sizes[$name] = 0.0;
            }
        }

        return $sizes;
    }

    protected function formatSizeMb(float $mb): string
    {
        if ($mb < 0.01) {
            return '0 MB';
        }
        if ($mb >= 1024) {
            return sprintf('%.2f GB', $mb / 1024);
        }

        return sprintf('%.2f MB', $mb);
    }
}
