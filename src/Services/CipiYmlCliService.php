<?php

namespace CipiApi\Services;

/**
 * cipi.yml via `sudo cipi yml …` (Cipi CLI ≥ 5.6.4; the API sudoers allow
 * `yml plan *`, `yml apply *` and `yml auto *` since 5.6.4).
 *
 * The plan is read-only: it parses the cipi.yml of the live release and
 * compares it with the server. Applying it is a job (`app-yml-apply`), since
 * it may switch PHP, rewrite vhosts and restart workers.
 *
 * Since Cipi 5.8.0 the file is picked per environment (`cipi-<env>.yml` or
 * `.yaml` before `cipi.yml`/`cipi.yaml`) and the JSON names the `env` the plan
 * was read for; older cores print none, which means production.
 */
class CipiYmlCliService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * What `cipi yml plan <app> --json` prints: the file, auto-apply, validity and
     * what applying would change.
     *
     * @return array{app: string, env: string, file: ?string, auto: bool, valid: ?bool, in_sync: ?bool, errors: list<string>, warnings: list<string>, changes: list<array{kind: string, summary: string}>, blockers: list<string>, notes: list<string>}
     */
    public function plan(string $app): array
    {
        $decoded = $this->runJson('yml plan ' . escapeshellarg($app) . ' --json', 'cipi yml plan');

        $changes = [];
        foreach ($decoded['changes'] ?? [] as $row) {
            if (! is_array($row) || ! isset($row['summary']) || ! is_scalar($row['summary'])) {
                continue;
            }
            $changes[] = [
                'kind' => is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '',
                'summary' => (string) $row['summary'],
            ];
        }

        return [
            'app' => $app,
            'env' => $this->envOrDefault($decoded['env'] ?? null),
            'file' => $this->stringOrNull($decoded['file'] ?? null),
            'auto' => (bool) ($decoded['auto'] ?? false),
            'valid' => $this->boolOrNull($decoded['valid'] ?? null),
            'in_sync' => $this->boolOrNull($decoded['in_sync'] ?? null),
            'errors' => $this->strings($decoded['errors'] ?? []),
            'warnings' => $this->strings($decoded['warnings'] ?? []),
            'changes' => $changes,
            'blockers' => $this->strings($decoded['blockers'] ?? []),
            'notes' => $this->strings($decoded['notes'] ?? []),
        ];
    }

    /**
     * `cipi yml auto <app> status --json`.
     *
     * @return array{app: string, env: string, auto: bool, sudoers: bool, file: ?string}
     */
    public function autoStatus(string $app): array
    {
        $decoded = $this->runJson('yml auto ' . escapeshellarg($app) . ' status --json', 'cipi yml auto status');

        return [
            'app' => $app,
            'env' => $this->envOrDefault($decoded['env'] ?? null),
            'auto' => (bool) ($decoded['auto'] ?? false),
            'sudoers' => (bool) ($decoded['sudoers'] ?? false),
            'file' => $this->stringOrNull($decoded['file'] ?? null),
        ];
    }

    /**
     * Turn automatic apply after every successful deploy on or off. Idempotent.
     *
     * @return array{app: string, env: string, auto: bool, sudoers: bool, file: ?string}
     */
    public function setAuto(string $app, bool $enabled): array
    {
        $mode = $enabled ? 'on' : 'off';
        $result = $this->cli->run('yml auto ' . escapeshellarg($app) . ' ' . $mode);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : "cipi yml auto {$mode} failed (Cipi CLI ≥ 5.6.4 required)");
        }

        return $this->autoStatus($app);
    }

    /**
     * @return array<string, mixed>
     */
    protected function runJson(string $command, string $label): array
    {
        $result = $this->cli->run($command);
        $output = trim($result['output'] ?? '');

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($output !== '' ? $output : "{$label} failed (Cipi CLI ≥ 5.6.4 required)");
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
            throw new \RuntimeException("{$label} --json returned invalid JSON (Cipi CLI ≥ 5.6.4 required)");
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    protected function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The environment label the CLI read the file for (Cipi 5.8.0); production
     * when an older core prints no `env`.
     */
    protected function envOrDefault(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'production';
    }

    protected function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
