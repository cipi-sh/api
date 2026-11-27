<?php

namespace CipiApi\Services;

/**
 * The mesh network of Zero Trust — Tailscale or NetBird — changed from the
 * panel (`sudo cipi zt tailscale|netbird …`, Cipi CLI ≥ 5.18.0, ability
 * `zt-manage`): join and leave a network, close and reopen the SSH port and
 * 80/443, Tailscale SSH on and off.
 *
 * Synchronous: the join key is a flag of the command, like every other
 * secret, and so never reaches the jobs table. It is never returned.
 *
 * The CLI holds the line when sudo comes from www-data, and this class only
 * passes it what it needs to: --force is ignored; `lock ssh` wants a login
 * sshd accepted over the mesh and a node key that cannot expire; `lock http`
 * and `disable` are refused when they would cut the caller's own way to this
 * server — the address of the request goes with them (`--via=`). A refusal
 * is a {@see ZtRefusedException} with the CLI's words.
 *
 * Cloudflare's side of Zero Trust is not here: its status alone
 * ({@see CipiZtCliService}).
 */
class CipiZtMeshService
{
    /** The core that has `cipi zt tailscale|netbird` and the panel's sudoers for them. */
    public const MIN_CIPI = '5.18.0';

    public const PROVIDERS = ['tailscale', 'netbird'];

    public const PORTS = ['ssh', 'http'];

    /** A join key as the CLI takes it: no spaces, no quotes. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._~:?&=%+\/-]{8,512}$/';

    public const HOSTNAME_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';

    public const TAG_PATTERN = '/^(tag:)?[a-zA-Z][a-zA-Z0-9-]{0,62}$/';

    public const URL_PATTERN = '/^https?:\/\/[A-Za-z0-9._-]+(:[0-9]{1,5})?(\/[A-Za-z0-9._~\/-]*)?$/';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
        protected CipiZtCliService $zt,
        protected CipiIpWhitelistService $ips,
    ) {}

    /** @throws \RuntimeException on a core older than 5.18.0 */
    public function requireCore(): void
    {
        $this->version->require(self::MIN_CIPI, 'The mesh network from the panel');
    }

    /** The address a request comes from, as the CLI can read it — or '' when it is not one. */
    public function clientIp(?string $ip): string
    {
        $ip = $this->ips->normalizeClientIp((string) $ip);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /** Zero Trust as it is now, the mesh with what the caller may do from where it is. */
    public function status(string $client = ''): array
    {
        return $this->zt->status($client !== '' ? $client : null);
    }

    /** The mesh this server is on: tailscale, netbird, or null. */
    public function provider(): ?string
    {
        $provider = $this->zt->status()['mesh'] ?? null;

        return in_array($provider, self::PROVIDERS, true) ? $provider : null;
    }

    /**
     * What is wrong with a join request, or null. The rules are the CLI's,
     * said before it runs.
     *
     * @param  array<string, mixed>  $input
     */
    public static function joinError(array $input): ?string
    {
        $provider = $input['provider'] ?? null;
        if (! in_array($provider, self::PROVIDERS, true)) {
            return "'provider' is tailscale or netbird";
        }
        $key = $input['key'] ?? null;
        if ($key !== null && $key !== '' && (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key))) {
            return 'That does not look like a key (8 to 512 characters, no spaces, no quotes)';
        }
        $hostname = $input['hostname'] ?? null;
        if ($hostname !== null && $hostname !== '' && (! is_string($hostname) || ! preg_match(self::HOSTNAME_PATTERN, $hostname))) {
            return "Not a hostname: letters, digits and -, up to 63";
        }
        $url = $input['control_url'] ?? null;
        if ($url !== null && $url !== '' && (! is_string($url) || strlen($url) > 255 || ! preg_match(self::URL_PATTERN, $url))) {
            return "'control_url' is the URL of the coordination server: https://host[:port]";
        }
        $tags = self::tags($input['tags'] ?? null);
        if ($tags === null) {
            return "Not a tag: letters, digits and -, e.g. tag:cipi";
        }
        if ($provider === 'netbird') {
            foreach (['tags' => $tags !== [], 'ssh' => ! empty($input['ssh']), 'reauth' => ! empty($input['reauth'])] as $field => $given) {
                if ($given) {
                    return "'{$field}' is Tailscale's: NetBird has groups on its setup key, OpenSSH for SSH, and no re-authentication of a registered peer";
                }
            }
        }
        if (! empty($input['reauth']) && ($key === null || $key === '')) {
            return "'reauth' signs the machine in again: it needs a key";
        }

        return null;
    }

    /**
     * Tags as the CLI takes them (tag:a,tag:b), from a list or a comma-separated
     * string; [] for none, null when one is not a tag name.
     *
     * @return list<string>|null
     */
    public static function tags(mixed $tags): ?array
    {
        if ($tags === null || $tags === '' || $tags === []) {
            return [];
        }
        $list = is_array($tags) ? $tags : explode(',', (string) $tags);
        $out = [];
        foreach ($list as $tag) {
            if (! is_string($tag)) {
                return null;
            }
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            if (! preg_match(self::TAG_PATTERN, $tag)) {
                return null;
            }
            $out[] = str_starts_with($tag, 'tag:') ? $tag : 'tag:'.$tag;
        }

        return array_values(array_unique($out));
    }

    /**
     * `cipi zt <provider> enable …` for a request {@see joinError} found nothing wrong with.
     *
     * @param  array<string, mixed>  $input
     */
    public static function joinCommand(array $input): string
    {
        $provider = (string) $input['provider'];
        $cmd = 'zt '.$provider.' enable';
        $key = (string) ($input['key'] ?? '');
        if ($key !== '') {
            $cmd .= ' --'.($provider === 'tailscale' ? 'auth-key' : 'setup-key').'='.escapeshellarg($key);
        }
        if (($input['hostname'] ?? '') !== '' && $input['hostname'] !== null) {
            $cmd .= ' --hostname='.escapeshellarg((string) $input['hostname']);
        }
        if (($input['control_url'] ?? '') !== '' && $input['control_url'] !== null) {
            $cmd .= ' --'.($provider === 'tailscale' ? 'login-server' : 'management-url').'='.escapeshellarg((string) $input['control_url']);
        }
        $tags = self::tags($input['tags'] ?? null) ?? [];
        if ($provider === 'tailscale' && $tags !== []) {
            $cmd .= ' --tags='.escapeshellarg(implode(',', $tags));
        }
        foreach (['direct' => '--direct', 'accept_dns' => '--accept-dns', 'accept_routes' => '--accept-routes'] as $field => $flag) {
            if (! empty($input[$field])) {
                $cmd .= ' '.$flag;
            }
        }
        if ($provider === 'tailscale') {
            if (! empty($input['ssh'])) {
                $cmd .= ' --ssh';
            }
            if (! empty($input['reauth'])) {
                $cmd .= ' --reauth';
            }
        }

        return $cmd;
    }

    /**
     * Join a network: the client is installed from its vendor's repository,
     * the server signs in with the key, the mesh interface is let through
     * UFW. Nothing is closed.
     *
     * @param  array<string, mixed>  $input
     */
    public function join(array $input): string
    {
        $error = self::joinError($input);
        if ($error !== null) {
            throw new ZtRefusedException($error);
        }

        return $this->change(self::joinCommand($input), 'cipi zt '.$input['provider'].' enable failed', (string) ($input['key'] ?? ''));
    }

    /** Leave the network: what its locks closed is reopened first. Refused when the caller comes over it. */
    public function leave(string $client): string
    {
        $provider = $this->requireProvider();

        return $this->change('zt '.$provider.' disable --yes'.self::via($client), 'cipi zt '.$provider.' disable failed');
    }

    /** Close the SSH port, or 80/443, to the world: open on the mesh only. */
    public function lock(string $port, string $client): string
    {
        $provider = $this->requireProvider();
        if (! in_array($port, self::PORTS, true)) {
            throw new ZtRefusedException("'port' is ssh or http");
        }
        // lock ssh takes nothing more (the panel is not reached over SSH);
        // lock http is told where the caller comes from, last.
        $cmd = 'zt '.$provider.' lock '.$port.' --yes'.($port === 'http' ? self::via($client) : '');

        return $this->change($cmd, 'cipi zt '.$provider.' lock '.$port.' failed');
    }

    /** Reopen it to the world — unless Cloudflare's own lock holds it, which the CLI says. */
    public function unlock(string $port): string
    {
        $provider = $this->requireProvider();
        if (! in_array($port, self::PORTS, true)) {
            throw new ZtRefusedException("'port' is ssh or http");
        }

        return $this->change('zt '.$provider.' unlock '.$port, 'cipi zt '.$provider.' unlock '.$port.' failed');
    }

    /** Tailscale SSH: tailscaled answers port 22 of the tailnet address, by the tailnet's policy. */
    public function providerSsh(bool $on): string
    {
        $provider = $this->requireProvider();
        if ($provider !== 'tailscale') {
            throw new ZtRefusedException("NetBird's own SSH server is not turned on by Cipi: SSH over NetBird is OpenSSH, with the server's keys");
        }

        return $this->change('zt tailscale ssh '.($on ? 'enable' : 'disable'), 'cipi zt tailscale ssh failed');
    }

    /** --via=<address>, the last argument, or nothing when there is no address to give. */
    protected static function via(string $client): string
    {
        return $client !== '' ? ' --via='.escapeshellarg($client) : '';
    }

    protected function requireProvider(): string
    {
        $this->requireCore();
        $provider = $this->provider();
        if ($provider === null) {
            throw new ZtRefusedException('This server is on no mesh network');
        }

        return $provider;
    }

    /**
     * Run a change. A refusal is a ZtRefusedException with the CLI's words,
     * without colours — and without the key, should the CLI ever repeat it.
     */
    protected function change(string $command, string $fallback, string $secret = ''): string
    {
        $this->requireCore();
        $result = $this->cli->run($command);
        $out = CipiFirewallService::plain((string) ($result['output'] ?? ''));
        if ($secret !== '') {
            $out = str_replace($secret, '[key]', $out);
        }
        if ($result['exit_code'] !== 0) {
            throw new ZtRefusedException($out !== '' ? $out : $fallback);
        }

        return $out;
    }
}
