<?php

namespace CipiApi\Services;

/**
 * Optional host packages catalog via `sudo cipi package list --json`
 * (Cipi CLI ≥ 5.2.2). Read-only: install/remove run as root and stay on
 * the host CLI (they are not in the API sudoers).
 */
class CipiPackageCliService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @return list<array{id: string, packages: list<string>, description: string, installed: bool, partial: bool}>
     */
    public function list(): array
    {
        $result = $this->cli->run('package list --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi package list failed (Cipi CLI ≥ 5.2.2 required)');
        }

        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('cipi package list --json returned invalid JSON');
        }

        $packages = [];
        foreach ($decoded['packages'] ?? [] as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $packages[] = [
                'id' => (string) $row['id'],
                'packages' => array_values(array_map('strval', is_array($row['packages'] ?? null) ? $row['packages'] : [])),
                'description' => (string) ($row['description'] ?? ''),
                'installed' => (bool) ($row['installed'] ?? false),
                'partial' => (bool) ($row['partial'] ?? false),
            ];
        }

        return $packages;
    }
}
