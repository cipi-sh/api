<?php

namespace CipiApi\Services;

/**
 * Meilisearch for Laravel Scout via `sudo cipi search …` (Cipi CLI ≥ 5.2.2).
 *
 * Only the panel-safe subset is exposed: status/list (read) and per-app
 * enable/disable. install / upgrade / key rotate / remove stay with the
 * operator on the CLI (they are not in the API sudoers).
 */
class CipiSearchCliService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @return array{installed: bool, running: bool, version: ?string, host: ?string, port: ?int, health: ?string, data_size: ?string, apps: array<string, array>}
     */
    public function status(): array
    {
        $result = $this->cli->run('search status --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi search status failed (Cipi CLI ≥ 5.2.2 required)');
        }

        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('cipi search status --json returned invalid JSON');
        }

        return [
            'installed' => (bool) ($decoded['installed'] ?? false),
            'running' => (bool) ($decoded['running'] ?? false),
            'version' => isset($decoded['version']) && is_string($decoded['version']) ? $decoded['version'] : null,
            'host' => isset($decoded['host']) && is_string($decoded['host']) ? $decoded['host'] : null,
            'port' => isset($decoded['port']) && is_numeric($decoded['port']) ? (int) $decoded['port'] : null,
            'health' => isset($decoded['health']) && is_string($decoded['health']) ? $decoded['health'] : null,
            'data_size' => isset($decoded['data_size']) && is_string($decoded['data_size']) ? $decoded['data_size'] : null,
            'apps' => is_array($decoded['apps'] ?? null) ? $decoded['apps'] : [],
        ];
    }

    /**
     * Enable search for an app: mints a scoped API key and writes the
     * SCOUT_* / MEILISEARCH_* variables into shared/.env.
     */
    public function enable(string $app): void
    {
        $result = $this->cli->run('search enable ' . escapeshellarg($app));
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi search enable failed');
        }
    }

    /**
     * Disable search for an app (restores the previous SCOUT_DRIVER;
     * indexes are never purged from the API).
     */
    public function disable(string $app): void
    {
        $result = $this->cli->run('search disable ' . escapeshellarg($app));
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi search disable failed');
        }
    }
}
