<?php

namespace CipiApi\Services;

/**
 * Cloudflare Zero Trust status via `sudo cipi zt status` (Cipi CLI ≥ 5.3.0).
 * Read-only by design: the API sudoers allow `zt status` only — enable,
 * hostname, access and lock commands stay with the operator on the CLI.
 */
class CipiZtCliService
{
    public function __construct(
        protected CipiCliService $cli,
        protected CipiOutputParser $parser,
    ) {}

    /**
     * @return array{enabled: bool, cloudflared: ?string, tunnel: ?string, real_ip: ?string, lock_http: ?string, lock_ssh: ?string, ssh_hostname: ?string, raw: string}
     */
    public function status(): array
    {
        $result = $this->cli->run('zt status');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi zt status failed (Cipi CLI ≥ 5.3.0 required)');
        }

        $plain = $this->parser->stripAnsi($result['output'] ?? '');
        $enabled = ! str_contains($plain, 'not enabled');

        $label = static function (string $name) use ($plain): ?string {
            if (preg_match('/^\s*' . preg_quote($name, '/') . '\s{2,}(.+?)\s*$/m', $plain, $m)) {
                $value = trim($m[1]);

                return $value !== '' ? $value : null;
            }

            return null;
        };

        return [
            'enabled' => $enabled,
            'cloudflared' => $enabled ? $label('cloudflared') : null,
            'tunnel' => $enabled ? $label('Tunnel') : null,
            'real_ip' => $enabled ? $label('real_ip') : null,
            'lock_http' => $enabled ? $label('lock http') : null,
            'lock_ssh' => $enabled ? $label('lock ssh') : null,
            'ssh_hostname' => $enabled ? $label('SSH hostname') : null,
            'raw' => trim($plain),
        ];
    }
}
