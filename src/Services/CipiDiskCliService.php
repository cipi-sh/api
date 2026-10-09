<?php

namespace CipiApi\Services;

/**
 * Disk usage via `sudo cipi disk --json` and `sudo cipi disk db --json`
 * (Cipi CLI ≥ 5.5.0; the API sudoers allow `disk` since 5.5.2).
 *
 * Read-only: the CLI measures every app home (`du`) and asks each database
 * engine for its sizes when the command runs, so a server with large apps
 * takes a few seconds. The soft limit itself is set through `app limits`.
 */
class CipiDiskCliService
{
    /** Engines in the order `cipi disk db` prints them. */
    public const ENGINES = ['mariadb', 'pgsql', 'valkey', 'meilisearch'];

    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * The filesystem /home is on, then every app (largest first) — same fields as `cipi disk --json`.
     *
     * @return array{disk: array{mount: string, size_gb: float, used_gb: float, free_gb: float, used_percent: int}, apps: list<array<string, mixed>>, apps_total_gb: float, apps_percent: float, other_gb: float, other_percent: float}
     */
    public function usage(): array
    {
        $decoded = $this->runJson('disk --json', 'cipi disk');

        $disk = is_array($decoded['disk'] ?? null) ? $decoded['disk'] : [];

        $apps = [];
        foreach ($decoded['apps'] ?? [] as $row) {
            if (! is_array($row) || ! isset($row['app']) || ! is_scalar($row['app']) || (string) $row['app'] === '') {
                continue;
            }
            $limit = $this->numberOrNull($row['limit_gb'] ?? null);
            $apps[] = [
                'app' => (string) $row['app'],
                'files_gb' => $this->number($row['files_gb'] ?? 0),
                'database_gb' => $this->number($row['database_gb'] ?? 0),
                'total_gb' => $this->number($row['total_gb'] ?? 0),
                'percent' => $this->number($row['percent'] ?? 0),
                'files_kb' => (int) ($row['files_kb'] ?? 0),
                'database_kb' => (int) ($row['database_kb'] ?? 0),
                'total_kb' => (int) ($row['total_kb'] ?? 0),
                'limit_gb' => $limit,
                'limit_percent' => $limit === null ? null : (int) ($row['limit_percent'] ?? 0),
                'over_limit' => $limit !== null && (bool) ($row['over_limit'] ?? false),
            ];
        }

        return [
            'disk' => [
                'mount' => (string) ($disk['mount'] ?? '/'),
                'size_gb' => $this->number($disk['size_gb'] ?? 0),
                'used_gb' => $this->number($disk['used_gb'] ?? 0),
                'free_gb' => $this->number($disk['free_gb'] ?? 0),
                'used_percent' => (int) ($disk['used_percent'] ?? 0),
            ],
            'apps' => $apps,
            'apps_total_gb' => $this->number($decoded['apps_total_gb'] ?? 0),
            'apps_percent' => $this->number($decoded['apps_percent'] ?? 0),
            'other_gb' => $this->number($decoded['other_gb'] ?? 0),
            'other_percent' => $this->number($decoded['other_percent'] ?? 0),
        ];
    }

    /**
     * Every database of every installed engine — `cipi disk db --json`, one entry per engine.
     *
     * @return list<array{engine: string, databases: list<array<string, mixed>>, on_disk_mb: ?float, memory_mb: ?float, note: ?string}>
     */
    public function databases(): array
    {
        $decoded = $this->runJson('disk db --json', 'cipi disk db');

        $engines = [];
        $names = array_values(array_unique(array_merge(self::ENGINES, array_keys($decoded))));
        foreach ($names as $engine) {
            if (! is_string($engine) || ! is_array($decoded[$engine] ?? null)) {
                continue;
            }
            $data = $decoded[$engine];

            $databases = [];
            foreach ($data['databases'] ?? [] as $row) {
                if (! is_array($row) || ! isset($row['name']) || ! is_scalar($row['name'])) {
                    continue;
                }
                $item = [
                    'name' => (string) $row['name'],
                    'size_mb' => $this->numberOrNull($row['size_mb'] ?? null),
                ];
                if (isset($row['keys']) && is_numeric($row['keys'])) {
                    $item['keys'] = (int) $row['keys'];
                }
                if (isset($row['documents']) && is_numeric($row['documents'])) {
                    $item['documents'] = (int) $row['documents'];
                }
                $databases[] = $item;
            }

            $note = $data['note'] ?? null;

            $engines[] = [
                'engine' => $engine,
                'databases' => $databases,
                'on_disk_mb' => $this->numberOrNull($data['on_disk_mb'] ?? null),
                'memory_mb' => $this->numberOrNull($data['memory_mb'] ?? null),
                'note' => is_string($note) && $note !== '' ? $note : null,
            ];
        }

        return $engines;
    }

    /**
     * @return array<string, mixed>
     */
    protected function runJson(string $command, string $label): array
    {
        $result = $this->cli->run($command);
        $output = trim($result['output'] ?? '');

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($output !== '' ? $output : "{$label} failed (Cipi CLI ≥ 5.5.2 required)");
        }

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            // A warning printed before the document (stderr is merged): keep the JSON object only.
            $start = strpos($output, '{');
            $end = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$label} --json returned invalid JSON");
        }

        return $decoded;
    }

    protected function number(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    protected function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
