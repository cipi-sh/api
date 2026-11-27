<?php

namespace CipiApi\Services;

/**
 * Zero Trust status via `sudo cipi zt status` (Cipi CLI ≥ 5.3.0): Cloudflare
 * Tunnel and, from Cipi 5.18.0, the mesh network the server is on (Tailscale
 * or NetBird). Read-only by design: the API sudoers allow `zt status` only —
 * enable, hostname, access, lock and every `zt tailscale|netbird` command
 * stay with the operator on the CLI.
 *
 * Cipi ≥ 5.16.0 answers `zt status --json` with the whole picture (the
 * tunnel and its connectors, every hostname with its Access paths and
 * people, service tokens, TCP services, SSH certificates, previews): it is
 * `details`. An older Cipi prints its text status for the same command, and
 * the summary fields are read from that text, as before.
 *
 * Cipi ≥ 5.18.0 adds `details.mesh` (provider, connected, address, peers,
 * node key expiry, locks, warnings) and the summary gets `mesh`,
 * `mesh_state` and `mesh_ip`: null while no mesh is on, and on an older Cipi.
 */
class CipiZtCliService
{
    public function __construct(
        protected CipiCliService $cli,
        protected CipiOutputParser $parser,
    ) {}

    /**
     * @return array{enabled: bool, cloudflared: ?string, tunnel: ?string, real_ip: ?string, lock_http: ?string, lock_ssh: ?string, ssh_hostname: ?string, mesh: ?string, mesh_state: ?string, mesh_ip: ?string, raw: string, details: ?array}
     */
    public function status(): array
    {
        $result = $this->cli->run('zt status --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi zt status failed (Cipi CLI ≥ 5.3.0 required)');
        }

        $output = (string) ($result['output'] ?? '');
        $json = $this->decode($output);

        if ($json !== null) {
            return self::fromJson($json);
        }

        return $this->fromText($output);
    }

    /** The JSON of Cipi ≥ 5.16.0, or null for the text of an older one. */
    protected function decode(string $output): ?array
    {
        // A line sudo or the shell printed first (a warning) comes before the JSON.
        $plain = trim($this->parser->stripAnsi($output));
        if (! preg_match('/^\{/m', $plain, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $json = json_decode(substr($plain, $m[0][1]), true);

        return is_array($json) && array_key_exists('enabled', $json) ? $json : null;
    }

    /**
     * The summary the endpoint always had, from the JSON, plus the JSON itself.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromJson(array $json): array
    {
        $enabled = (bool) ($json['enabled'] ?? false);
        $tunnel = is_array($json['tunnel'] ?? null) ? $json['tunnel'] : [];
        $lock = is_array($json['lock'] ?? null) ? $json['lock'] : [];
        $ssh = is_array($json['ssh'] ?? null) ? $json['ssh'] : [];
        $mode = $lock['http_mode'] ?? null;
        // `enabled` and the fields above are Cloudflare's, as they always were;
        // the mesh (Cipi ≥ 5.18.0) is on or off on its own.
        $mesh = is_array($json['mesh'] ?? null) ? $json['mesh'] : [];
        $provider = ! empty($mesh['enabled']) && is_string($mesh['provider'] ?? null) && $mesh['provider'] !== '' ? $mesh['provider'] : null;
        $meshIp = $provider !== null && is_string($mesh['ip'] ?? null) && $mesh['ip'] !== '' ? $mesh['ip'] : null;

        return [
            'enabled' => $enabled,
            'cloudflared' => $enabled ? (! empty($tunnel['running']) ? 'running' : 'stopped') : null,
            'tunnel' => $enabled ? (($tunnel['name'] ?? null) ?: ($tunnel['id'] ?? null)) : null,
            'real_ip' => $enabled ? (! empty($json['real_ip']) ? 'CF-Connecting-IP' : 'off') : null,
            'lock_http' => $enabled ? ((! empty($lock['http']) ? 'true' : 'false').(is_string($mode) && $mode !== '' ? ' ('.$mode.')' : '')) : null,
            'lock_ssh' => $enabled ? (! empty($lock['ssh']) ? 'true' : 'false') : null,
            'ssh_hostname' => $enabled ? (($ssh['hostname'] ?? null) ?: null) : null,
            'mesh' => $provider,
            'mesh_state' => $provider !== null ? (! empty($mesh['connected']) ? 'connected' : 'disconnected') : null,
            'mesh_ip' => $meshIp,
            'raw' => '',
            'details' => $json,
        ];
    }

    protected function fromText(string $output): array
    {
        $plain = $this->parser->stripAnsi($output);
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
            'mesh' => null,
            'mesh_state' => null,
            'mesh_ip' => null,
            'raw' => trim($plain),
            'details' => null,
        ];
    }
}
