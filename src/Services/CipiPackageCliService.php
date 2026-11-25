<?php

namespace CipiApi\Services;

/**
 * Optional host packages via `sudo cipi package …`.
 *
 * - `list()` — the catalog with its install state (`package list --json`,
 *   Cipi CLI ≥ 5.2.2; `kind`, `binaries` and `missing` from 5.14.0).
 * - `installCommand()` / `removeCommand()` — what the install and remove jobs
 *   run (Cipi CLI ≥ 5.14.0, whose sudoers let the panel do it). The catalog is
 *   closed: an id outside it never reaches the command, and the CLI refuses it
 *   too. `--yes` is always passed; `--force` and `--autoremove` never are (the
 *   CLI ignores them from the panel anyway).
 */
class CipiPackageCliService
{
    /** The core whose sudoers let the panel install and remove. */
    public const MIN_CIPI_MANAGE = '5.14.0';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /**
     * @return list<array{id: string, packages: list<string>, description: string, kind: string, binaries: list<string>, missing: list<string>, installed: bool, partial: bool}>
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

        $strings = static fn (mixed $v): array => array_values(array_map('strval', is_array($v) ? $v : []));

        $packages = [];
        foreach ($decoded['packages'] ?? [] as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $binaries = $strings($row['binaries'] ?? null);
            $packages[] = [
                'id' => (string) $row['id'],
                'packages' => $strings($row['packages'] ?? null),
                'description' => (string) ($row['description'] ?? ''),
                // Before 5.14.0 every entry was a tool with binaries.
                'kind' => in_array($row['kind'] ?? null, ['tools', 'libraries'], true) ? $row['kind'] : 'tools',
                'binaries' => $binaries,
                'missing' => $strings($row['missing'] ?? null),
                'installed' => (bool) ($row['installed'] ?? false),
                'partial' => (bool) ($row['partial'] ?? false),
            ];
        }

        return $packages;
    }

    /** The catalog entry with this id on this server, null when there is none. */
    public function find(string $id): ?array
    {
        foreach ($this->list() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @throws \RuntimeException on a core whose sudoers do not let the panel install
     */
    public function requireManage(): void
    {
        $this->version->require(self::MIN_CIPI_MANAGE, 'Installing and removing packages from the panel');
    }

    /** A catalog id as the CLI writes them: lowercase letters, digits, dashes. */
    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,39}\z/', $id);
    }

    public static function installCommand(string $id): string
    {
        return 'package install ' . escapeshellarg($id) . ' --yes';
    }

    public static function removeCommand(string $id): string
    {
        return 'package remove ' . escapeshellarg($id) . ' --yes';
    }
}
