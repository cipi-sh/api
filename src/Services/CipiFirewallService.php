<?php

namespace CipiApi\Services;

/**
 * The server's firewall (Cipi CLI ≥ 5.14.0): UFW, fail2ban and CrowdSec.
 *
 * One read — `cipi firewall status --json` — and the changes the panel may
 * make: open a port (`firewall allow`), remove what allow made (`firewall
 * remove`), block or unblock an address, the fail2ban threshold, unban, and
 * CrowdSec's allowlist; enabling and disabling CrowdSec are jobs (see the
 * controller). Everything is validated here before a command is built, and
 * the CLI checks it again: it never closes the ports the server is reached
 * on, never undoes a Zero Trust lock, and ignores --force from the panel.
 *
 * What the API adds on top: it never blocks the address the request comes
 * from (the panel would lose the server), and enabling CrowdSec allowlists it.
 */
class CipiFirewallService
{
    /** The core whose `cipi firewall status --json` and panel sudoers this needs. */
    public const MIN_CIPI = '5.14.0';

    /** The core that reports `real_ip` and lets the panel run `cipi nginx real-ip`. */
    public const REAL_IP_MIN_CIPI = '5.15.0';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
        protected CipiIpWhitelistService $ips,
    ) {}

    /** @throws \RuntimeException on a core older than 5.14.0 */
    public function requireCore(string $feature = 'The firewall from the panel'): void
    {
        $this->version->require(self::MIN_CIPI, $feature);
    }

    // ── read ──────────────────────────────────────────────────────

    /**
     * @return array{ufw: array, protected_ports: list<int>, zero_trust: array, fail2ban: array, crowdsec: array}
     */
    public function status(): array
    {
        $this->requireCore();
        $result = $this->cli->run('firewall status --json');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException(self::plain($result['output'] ?? '') ?: 'cipi firewall status failed');
        }
        $decoded = json_decode(trim((string) ($result['output'] ?? '')), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('cipi firewall status --json returned invalid JSON');
        }

        return self::normalizeStatus($decoded);
    }

    /**
     * The CLI's JSON with every key present and typed: a client can read it
     * without checking what an older or partial answer left out.
     */
    public static function normalizeStatus(array $s): array
    {
        $ufw = is_array($s['ufw'] ?? null) ? $s['ufw'] : [];
        $f2b = is_array($s['fail2ban'] ?? null) ? $s['fail2ban'] : [];
        $cs = is_array($s['crowdsec'] ?? null) ? $s['crowdsec'] : [];
        $zt = is_array($s['zero_trust'] ?? null) ? $s['zero_trust'] : [];
        $int = static fn ($v): ?int => is_numeric($v) ? (int) $v : null;
        $strings = static fn ($v): array => array_values(array_map('strval', array_filter(is_array($v) ? $v : [], 'is_scalar')));

        $rules = [];
        foreach (is_array($ufw['rules'] ?? null) ? $ufw['rules'] : [] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rules[] = [
                'action' => (string) ($r['action'] ?? ''),
                'port' => (string) ($r['port'] ?? 'any'),
                'proto' => (string) ($r['proto'] ?? 'any'),
                'from' => (string) ($r['from'] ?? 'any'),
                'to' => (string) ($r['to'] ?? 'any'),
                'direction' => (string) ($r['direction'] ?? 'in'),
                'interface' => (string) ($r['interface'] ?? ''),
                'app' => (string) ($r['app'] ?? ''),
                'comment' => (string) ($r['comment'] ?? ''),
                'family' => in_array($r['family'] ?? null, ['both', 'v4', 'v6'], true) ? $r['family'] : 'both',
                'log' => (bool) ($r['log'] ?? false),
                'kind' => in_array($r['kind'] ?? null, ['port', 'block', 'other'], true) ? $r['kind'] : 'other',
                'managed_by' => (string) ($r['managed_by'] ?? ''),
                'protected' => (bool) ($r['protected'] ?? false),
                'removable' => (bool) ($r['removable'] ?? false),
            ];
        }

        $jails = [];
        foreach (is_array($f2b['jails'] ?? null) ? $f2b['jails'] : [] as $j) {
            if (is_array($j) && is_string($j['jail'] ?? null)) {
                $jails[] = [
                    'jail' => $j['jail'],
                    'banned' => $strings($j['banned'] ?? []),
                    'count' => (int) ($j['count'] ?? 0),
                    'total' => (int) ($j['total'] ?? 0),
                    'failed' => (int) ($j['failed'] ?? 0),
                ];
            }
        }

        $decisions = [];
        foreach (is_array($cs['decisions'] ?? null) ? $cs['decisions'] : [] as $d) {
            if (is_array($d) && ($d['ip'] ?? '') !== '') {
                $decisions[] = [
                    'ip' => (string) $d['ip'],
                    'scope' => (string) ($d['scope'] ?? ''),
                    'type' => (string) ($d['type'] ?? ''),
                    'reason' => (string) ($d['reason'] ?? ''),
                    'origin' => (string) ($d['origin'] ?? ''),
                    'duration' => (string) ($d['duration'] ?? ''),
                ];
            }
        }

        $rip = is_array($s['real_ip'] ?? null) ? $s['real_ip'] : null;

        return [
            'ufw' => [
                'installed' => (bool) ($ufw['installed'] ?? false),
                'active' => (bool) ($ufw['active'] ?? false),
                'default_incoming' => isset($ufw['default_incoming']) ? (string) $ufw['default_incoming'] : null,
                'default_outgoing' => isset($ufw['default_outgoing']) ? (string) $ufw['default_outgoing'] : null,
                'rules' => $rules,
            ],
            'protected_ports' => array_values(array_map('intval', array_filter(is_array($s['protected_ports'] ?? null) ? $s['protected_ports'] : [], 'is_numeric'))),
            'zero_trust' => [
                'lock_http' => (bool) ($zt['lock_http'] ?? false),
                'lock_ssh' => (bool) ($zt['lock_ssh'] ?? false),
            ],
            'fail2ban' => [
                'installed' => (bool) ($f2b['installed'] ?? false),
                'running' => (bool) ($f2b['running'] ?? false),
                'attempts' => $int($f2b['attempts'] ?? null),
                'attempts_default' => $int($f2b['attempts_default'] ?? null),
                'attempts_custom' => $int($f2b['attempts_custom'] ?? null),
                'attempts_pending' => (bool) ($f2b['attempts_pending'] ?? false),
                'findtime' => $int($f2b['findtime'] ?? null),
                'bantime' => $int($f2b['bantime'] ?? null),
                'jails' => $jails,
            ],
            'crowdsec' => [
                'installed' => (bool) ($cs['installed'] ?? false),
                'running' => (bool) ($cs['running'] ?? false),
                'bouncer' => (string) ($cs['bouncer'] ?? 'not installed'),
                'mode' => (string) ($cs['mode'] ?? ''),
                'rescue' => (string) ($cs['rescue'] ?? 'stopped'),
                'rescue_port' => $int($cs['rescue_port'] ?? null),
                'allowlist' => $strings($cs['allowlist'] ?? []),
                'decisions' => $decisions,
                'ram_available_mb' => $int($cs['ram_available_mb'] ?? null),
                'ram_required_mb' => $int($cs['ram_required_mb'] ?? null),
                'enable_blockers' => $strings($cs['enable_blockers'] ?? []),
            ],
            // null: a core that does not report it (and does not let the panel change it).
            'real_ip' => $rip === null ? null : [
                'enabled' => (bool) ($rip['enabled'] ?? false),
                'provider' => is_string($rip['provider'] ?? null) ? $rip['provider'] : null,
                'header' => is_string($rip['header'] ?? null) ? $rip['header'] : null,
                'kept_by' => in_array($rip['kept_by'] ?? null, ['real-ip', 'zero-trust', 'both', 'other'], true) ? $rip['kept_by'] : null,
                'ranges' => (int) ($rip['ranges'] ?? 0),
                'refreshed_at' => is_string($rip['refreshed_at'] ?? null) ? $rip['refreshed_at'] : null,
                'nightly_refresh' => (bool) ($rip['nightly_refresh'] ?? false),
                'can_disable' => (bool) ($rip['can_disable'] ?? false),
                'bans_active' => (bool) ($rip['bans_active'] ?? false),
            ],
        ];
    }

    // ── validation ────────────────────────────────────────────────

    /** A port (1–65535) or a range (8000:8100), without leading zeros; null when it is neither. */
    public static function normalizePort(mixed $port): ?string
    {
        $port = trim((string) (is_scalar($port) ? $port : ''));
        if (! preg_match('/^(\d{1,5})(?::(\d{1,5}))?$/', $port, $m)) {
            return null;
        }
        $lo = (int) $m[1];
        if ($lo < 1 || $lo > 65535) {
            return null;
        }
        if (! isset($m[2]) || $m[2] === '') {
            return (string) $lo;
        }
        $hi = (int) $m[2];

        return $hi > $lo && $hi <= 65535 ? $lo.':'.$hi : null;
    }

    /** An IPv4/IPv6 address or CIDR range, as the CLI accepts it. */
    public function isAddress(mixed $net): bool
    {
        return is_string($net) && $net !== '' && $net !== '*' && strlen($net) <= 49 && $this->ips->isValidEntry($net);
    }

    /** Wider than IPv4 /8 or IPv6 /16: blocking it would take in a good part of the Internet. */
    public static function tooWide(string $net): bool
    {
        if (! str_contains($net, '/')) {
            return false;
        }
        $mask = (int) substr($net, strpos($net, '/') + 1);

        return str_contains($net, ':') ? $mask < 16 : $mask < 8;
    }

    /** The request's address, IPv4-mapped IPv6 written as IPv4. */
    public function clientIp(?string $ip): string
    {
        return $this->ips->normalizeClientIp((string) $ip);
    }

    /** True when blocking $net would take in $client — the panel, or whoever calls the API. */
    public function takesIn(string $net, string $client): bool
    {
        return $client !== '' && $this->ips->ipMatches($client, $net);
    }

    // ── changes ───────────────────────────────────────────────────

    public function allow(string $port, ?string $from, string $proto = 'tcp'): string
    {
        $cmd = 'firewall allow '.escapeshellarg($port);
        if ($from !== null && $from !== '') {
            $cmd .= ' --from='.escapeshellarg($from);
        }
        if ($proto !== 'tcp') {
            $cmd .= ' --proto='.escapeshellarg($proto);
        }

        return $this->change($cmd, 'cipi firewall allow failed');
    }

    /** $action allow|deny — the rule made by `firewall allow` or `deny`. */
    public function remove(string $port, ?string $from, string $proto = 'tcp', string $action = 'allow'): string
    {
        $cmd = 'firewall remove '.escapeshellarg($port);
        if ($from !== null && $from !== '') {
            $cmd .= ' --from='.escapeshellarg($from);
        }
        if ($proto !== 'tcp') {
            $cmd .= ' --proto='.escapeshellarg($proto);
        }
        if ($action === 'deny') {
            $cmd .= ' --deny';
        }

        return $this->change($cmd, 'cipi firewall remove failed');
    }

    public function block(string $net): string
    {
        return $this->change('firewall block '.escapeshellarg($net), 'cipi firewall block failed');
    }

    public function unblock(string $net): string
    {
        return $this->change('firewall unblock '.escapeshellarg($net), 'cipi firewall unblock failed');
    }

    /** null: back to the default (3). */
    public function attempts(?int $attempts): string
    {
        return $this->change('firewall attempts '.($attempts === null ? 'default' : (string) $attempts), 'cipi firewall attempts failed');
    }

    /** fail2ban's jails and CrowdSec's decisions alike (`cipi ban unban`). */
    public function unban(string $ip): string
    {
        $out = $this->change('ban unban '.escapeshellarg($ip), 'cipi ban unban failed');
        if (str_contains($out, 'is not currently banned')) {
            throw new \InvalidArgumentException("{$ip} is not currently banned");
        }

        return $out;
    }

    public function crowdsecAllow(string $net): string
    {
        return $this->change('crowdsec allow '.escapeshellarg($net), 'cipi crowdsec allow failed');
    }

    public function crowdsecUnallow(string $net): string
    {
        $out = $this->change('crowdsec unallow '.escapeshellarg($net), 'cipi crowdsec unallow failed');
        if (str_contains($out, 'is not on the extra allowlist')) {
            throw new \InvalidArgumentException("{$net} is not on the CrowdSec allowlist");
        }

        return $out;
    }

    // ── the visitor's address behind Cloudflare (`cipi nginx real-ip`) ──

    /** nginx takes the visitor's address from Cloudflare (CF-Connecting-IP); again: the ranges refreshed. */
    public function realIpEnable(): string
    {
        $this->version->require(self::REAL_IP_MIN_CIPI, 'Cloudflare real IP from the panel');

        return $this->change('nginx real-ip cloudflare', 'cipi nginx real-ip cloudflare failed');
    }

    /** Cloudflare's ranges fetched again (nginx, fail2ban, CrowdSec); the server does it every night too. */
    public function realIpRefresh(): string
    {
        $this->version->require(self::REAL_IP_MIN_CIPI, 'Cloudflare real IP from the panel');

        return $this->change('nginx real-ip refresh', 'cipi nginx real-ip refresh failed');
    }

    /**
     * Back to the address of each connection. --yes: the panel asked first,
     * with what the CLI warns of (fail2ban or CrowdSec could ban Cloudflare).
     * Zero Trust keeps it on; the CLI says so and changes nothing.
     */
    public function realIpDisable(): string
    {
        $this->version->require(self::REAL_IP_MIN_CIPI, 'Cloudflare real IP from the panel');

        return $this->change('nginx real-ip off --yes', 'cipi nginx real-ip off failed');
    }

    /** `crowdsec enable`, for a job: the caller's address goes on the allowlist. */
    public static function crowdsecEnableCommand(string $client): string
    {
        return 'crowdsec enable'.($client !== '' ? ' --allow='.escapeshellarg($client) : '');
    }

    /**
     * Run a change; the CLI's refusal (a protected port, a Zero Trust lock,
     * a rule that is not there) is an InvalidArgumentException with its
     * message, without colours.
     */
    protected function change(string $command, string $fallback): string
    {
        $this->requireCore();
        $result = $this->cli->run($command);
        $out = self::plain($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \InvalidArgumentException($out !== '' ? $out : $fallback);
        }

        return $out;
    }

    /** The CLI's output for people: no colours, no [INFO]/[ERROR] tags, no blank lines. */
    public static function plain(string $output): string
    {
        $text = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $text = preg_replace('/^\[(INFO|WARN|ERROR)\]\s*/m', '', $text) ?? $text;
        $text = preg_replace('/^[✓✗→]\s*/mu', '', $text) ?? $text;

        return trim(preg_replace('/\n\s*\n+/', "\n", $text) ?? $text);
    }
}
