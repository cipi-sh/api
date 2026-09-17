<?php

namespace CipiApi\Services;

/**
 * Deploy audit ledger via `sudo cipi deploy <app> --audit --json`
 * (Cipi CLI ≥ 5.4.0). One record per deploy event (published / failed /
 * rollback), whatever started it, from the root-only hash-chained ledger.
 */
class CipiDeployAuditCliService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @return list<array> Ledger records for the app (newest last), or [] when
     *                     the ledger does not exist yet.
     */
    public function show(string $app, int $days = 90): array
    {
        $command = 'deploy ' . escapeshellarg($app)
            . ' --audit --days=' . escapeshellarg((string) $days) . ' --json';

        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi deploy --audit failed (Cipi CLI ≥ 5.4.0 required)');
        }

        $output = trim($result['output'] ?? '');
        // No ledger yet: the CLI prints a warning instead of JSON.
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
