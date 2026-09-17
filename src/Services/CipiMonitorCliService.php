<?php

namespace CipiApi\Services;

/**
 * System monitor checks via `sudo cipi monitor list --json` (Cipi CLI ≥ 5.3.0).
 * Read-only: enabling/disabling checks and thresholds stay on the host CLI.
 */
class CipiMonitorCliService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @return array{reminder_minutes: int, checks: list<array{check: string, config: array, state: ?string, last_alert: ?int}>}
     */
    public function list(): array
    {
        $result = $this->cli->run('monitor list --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi monitor list failed (Cipi CLI ≥ 5.3.0 required)');
        }

        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('cipi monitor list --json returned invalid JSON');
        }

        $checks = [];
        foreach ($decoded['checks'] ?? [] as $row) {
            if (! is_array($row) || empty($row['check'])) {
                continue;
            }
            $checks[] = [
                'check' => (string) $row['check'],
                'config' => is_array($row['config'] ?? null) ? $row['config'] : [],
                'state' => isset($row['state']) && is_string($row['state']) && $row['state'] !== '' ? $row['state'] : null,
                'last_alert' => isset($row['last_alert']) && is_numeric($row['last_alert']) ? (int) $row['last_alert'] : null,
            ];
        }

        return [
            'reminder_minutes' => (int) ($decoded['reminder_minutes'] ?? 240),
            'checks' => $checks,
        ];
    }
}
