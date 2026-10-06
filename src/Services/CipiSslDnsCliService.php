<?php

namespace CipiApi\Services;

/**
 * Cloudflare accounts for DNS-01 certificates (`sudo cipi ssl dns …`, Cipi CLI ≥ 5.5.0).
 *
 * Tokens are written root-only by the CLI and never returned. `set` runs synchronously so
 * the token is never persisted in the queue or in the jobs table.
 */
class CipiSslDnsCliService
{
    public const ACCOUNT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    public const TOKEN_PATTERN = '/^[\x21-\x7E]{20,255}$/';

    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * Error for `ssl install` options that exclude each other, or null.
     */
    public function installOptionsError(?string $dns, ?string $account, ?bool $wildcard, bool $http): ?string
    {
        if ($dns !== null && $dns !== 'cloudflare') {
            return 'Supported DNS providers: cloudflare';
        }
        if ($account !== null && ! preg_match(self::ACCOUNT_PATTERN, $account)) {
            return "Invalid account name '{$account}' (lowercase letters, digits, - and _, max 32)";
        }
        if ($http && $dns !== null) {
            return "'http' and 'dns' exclude each other";
        }
        if ($account !== null && $dns === null) {
            return "'account' selects a Cloudflare account for DNS-01: set 'dns' to cloudflare";
        }
        if ($http && $wildcard === true) {
            return "A wildcard certificate needs DNS-01 (Let's Encrypt validates wildcard names over DNS only)";
        }

        return null;
    }

    /**
     * `cipi ssl install` command. `wildcard` null keeps what the certificate had last time.
     */
    public function installCommand(string $app, ?string $dns, ?string $account, ?bool $wildcard, bool $http): string
    {
        $command = 'ssl install ' . escapeshellarg($app);
        if ($dns !== null) {
            $command .= ' --dns=' . escapeshellarg($dns);
        }
        if ($account !== null) {
            $command .= ' --account=' . escapeshellarg($account);
        }
        if ($wildcard !== null) {
            $command .= $wildcard ? ' --wildcard' : ' --no-wildcard';
        }
        if ($http) {
            $command .= ' --http';
        }

        return $command;
    }

    /**
     * @return list<array{account: string, provider: string, certificates: list<string>}>
     */
    public function accounts(): array
    {
        $result = $this->cli->run('ssl dns list --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi ssl dns list failed (Cipi CLI ≥ 5.5.0 required)');
        }

        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded) || ! is_array($decoded['accounts'] ?? null)) {
            throw new \RuntimeException('cipi ssl dns list --json returned invalid JSON (Cipi CLI ≥ 5.5.0 required)');
        }

        $accounts = [];
        foreach ($decoded['accounts'] as $row) {
            if (! is_array($row) || ! is_string($row['account'] ?? null)) {
                continue;
            }
            $accounts[] = [
                'account' => $row['account'],
                'provider' => is_string($row['provider'] ?? null) ? $row['provider'] : 'cloudflare',
                'certificates' => array_values(array_filter(
                    is_array($row['certificates'] ?? null) ? $row['certificates'] : [],
                    'is_string',
                )),
            ];
        }

        return $accounts;
    }

    /**
     * Add an account, or replace its token (rotation). The first account may install
     * the certbot Cloudflare plugin on the host.
     */
    public function set(string $account, string $token): string
    {
        return $this->runOrFail(
            'ssl dns set --name=' . escapeshellarg($account) . ' --token=' . escapeshellarg($token),
            'cipi ssl dns set failed',
        );
    }

    /**
     * Remove an account. The CLI refuses while a certificate still renews with it.
     */
    public function remove(string $account): string
    {
        return $this->runOrFail('ssl dns remove ' . escapeshellarg($account), 'cipi ssl dns remove failed');
    }

    protected function runOrFail(string $command, string $fallback): string
    {
        $result = $this->cli->run($command);
        $plain = trim(preg_replace('/\x1b\[[0-9;]*m/', '', $result['output'] ?? ''));
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($plain !== '' ? $plain : $fallback);
        }

        return $plain;
    }
}
