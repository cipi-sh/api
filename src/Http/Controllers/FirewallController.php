<?php

namespace CipiApi\Http\Controllers;

use CipiApi\Services\CipiFirewallService;
use CipiApi\Services\CipiJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The server's firewall (Cipi CLI ≥ 5.14.0): UFW rules, fail2ban (threshold,
 * bans) and CrowdSec (on/off, allowlist, decisions). `GET /api/firewall`
 * needs `firewall-view`, everything else `firewall-manage`.
 *
 * Synchronous, and every change answers with the firewall as it is after it,
 * except enabling and disabling CrowdSec — packages to install or purge —
 * which are jobs (`crowdsec-enable`, `crowdsec-disable`). On an older core
 * every route answers 503 before anything runs.
 *
 * The visitor's address behind Cloudflare (`cipi nginx real-ip`, Cipi CLI ≥
 * 5.15.0): on, refreshed, off — synchronous too, under `firewall-manage`:
 * it decides which address fail2ban and CrowdSec ban.
 *
 * Never from here: denying or removing the ports the server is reached on,
 * undoing a Zero Trust lock (the CLI refuses both, and ignores --force from
 * the panel), blocking the address this request comes from, CrowdSec's
 * rescue listener.
 */
class FirewallController extends Controller
{
    public function __construct(
        protected CipiFirewallService $firewall,
        protected CipiJobService $jobs,
    ) {}

    public function status(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->firewall->status()], 200);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /** Open a port: `port` (or a range 8000:8100), optional `from` (IP or CIDR), `proto` tcp|udp. */
    public function allow(Request $request): JsonResponse
    {
        $v = $request->validate([
            'port' => 'required',
            'from' => 'nullable|string|max:49',
            'proto' => 'nullable|in:tcp,udp',
        ]);
        $port = CipiFirewallService::normalizePort($v['port']);
        if ($port === null) {
            return $this->invalid('port', 'A port (1-65535) or a range such as 8000:8100.');
        }
        $from = $this->optionalAddress($v['from'] ?? null);
        if ($from === false) {
            return $this->invalid('from', 'An IPv4/IPv6 address or a CIDR range.');
        }

        return $this->change(fn () => $this->firewall->allow($port, $from, $v['proto'] ?? 'tcp'));
    }

    /** Remove a rule made by allow (or deny): `port`, `from`, `proto` tcp|udp|any, `action` allow|deny. */
    public function remove(Request $request): JsonResponse
    {
        $v = $request->validate([
            'port' => 'required',
            'from' => 'nullable|string|max:49',
            'proto' => 'nullable|in:tcp,udp,any',
            'action' => 'nullable|in:allow,deny',
        ]);
        $port = CipiFirewallService::normalizePort($v['port']);
        if ($port === null) {
            return $this->invalid('port', 'A port (1-65535) or a range such as 8000:8100.');
        }
        $from = $this->optionalAddress($v['from'] ?? null);
        if ($from === false) {
            return $this->invalid('from', 'An IPv4/IPv6 address or a CIDR range.');
        }
        $proto = $v['proto'] ?? 'tcp';
        if ($proto === 'any' && str_contains($port, ':')) {
            return $this->invalid('proto', 'A port range always has a protocol: tcp or udp.');
        }

        return $this->change(fn () => $this->firewall->remove($port, $from, $proto, $v['action'] ?? 'allow'));
    }

    /** Refuse every port to `address` (IP or CIDR), ahead of every allow. */
    public function block(Request $request): JsonResponse
    {
        $v = $request->validate(['address' => 'required|string|max:49']);
        $net = trim($v['address']);
        if (! $this->firewall->isAddress($net)) {
            return $this->invalid('address', 'An IPv4/IPv6 address or a CIDR range.');
        }
        if (CipiFirewallService::tooWide($net)) {
            return $this->invalid('address', 'Too wide a range to block: IPv4 /8 and IPv6 /16 at most.');
        }
        $client = $this->firewall->clientIp($request->ip());
        if ($this->firewall->takesIn($net, $client)) {
            return $this->invalid('address', "{$net} takes in {$client}, the address this request comes from: blocking it would cut this server off from you.");
        }

        return $this->change(fn () => $this->firewall->block($net));
    }

    public function unblock(Request $request): JsonResponse
    {
        $v = $request->validate(['address' => 'required|string|max:49']);
        $net = trim($v['address']);
        if (! $this->firewall->isAddress($net)) {
            return $this->invalid('address', 'An IPv4/IPv6 address or a CIDR range.');
        }

        return $this->change(fn () => $this->firewall->unblock($net));
    }

    /** Failed SSH logins before fail2ban bans the address: `attempts` 1–100, null for the default (3). */
    public function attempts(Request $request): JsonResponse
    {
        $v = $request->validate(['attempts' => 'present|nullable|integer|min:1|max:100']);
        $n = $v['attempts'] === null ? null : (int) $v['attempts'];

        return $this->change(fn () => $this->firewall->attempts($n));
    }

    /** Lift a ban, fail2ban's or CrowdSec's: `ip` (a CIDR for a CrowdSec range). */
    public function unban(Request $request): JsonResponse
    {
        $v = $request->validate(['ip' => 'required|string|max:49']);
        $ip = trim($v['ip']);
        if (! $this->firewall->isAddress($ip)) {
            return $this->invalid('ip', 'An IPv4/IPv6 address.');
        }

        return $this->change(fn () => $this->firewall->unban($ip));
    }

    public function crowdsecAllow(Request $request): JsonResponse
    {
        $v = $request->validate(['address' => 'required|string|max:49']);
        $net = trim($v['address']);
        if (! $this->firewall->isAddress($net)) {
            return $this->invalid('address', 'An IPv4/IPv6 address or a CIDR range.');
        }

        return $this->change(fn () => $this->firewall->crowdsecAllow($net));
    }

    public function crowdsecUnallow(Request $request): JsonResponse
    {
        $v = $request->validate(['address' => 'required|string|max:49']);
        $net = trim($v['address']);
        if (! $this->firewall->isAddress($net)) {
            return $this->invalid('address', 'An IPv4/IPv6 address or a CIDR range.');
        }

        return $this->change(fn () => $this->firewall->crowdsecUnallow($net));
    }

    /**
     * Install CrowdSec and its firewall bouncer — a job of a few minutes.
     * What would stop it (RAM, a reverse proxy without real_ip) is a 422 at
     * once; the address of this request goes on its allowlist.
     */
    public function crowdsecEnable(Request $request): JsonResponse
    {
        try {
            $status = $this->firewall->status();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        $blockers = $status['crowdsec']['enable_blockers'];
        if ($blockers !== []) {
            return response()->json(['error' => 'CrowdSec cannot be enabled: '.implode(' · ', $blockers), 'blockers' => $blockers], 422);
        }
        $client = $this->firewall->clientIp($request->ip());
        if ($client !== '' && ! $this->firewall->isAddress($client)) {
            $client = '';
        }
        $job = $this->jobs->dispatch('crowdsec-enable', CipiFirewallService::crowdsecEnableCommand($client), ['allow' => $client]);

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /** Flush CrowdSec's firewall rules and remove it; fail2ban stays. A job. */
    public function crowdsecDisable(): JsonResponse
    {
        try {
            $status = $this->firewall->status();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        if (! $status['crowdsec']['installed']) {
            return response()->json(['error' => 'CrowdSec is not installed'], 409);
        }
        $job = $this->jobs->dispatch('crowdsec-disable', 'crowdsec disable --force');

        return response()->json(['job_id' => $job->id, 'status' => 'pending'], 202);
    }

    /**
     * nginx takes the visitor's address from Cloudflare's CF-Connecting-IP
     * for requests from Cloudflare's ranges (`cipi nginx real-ip cloudflare`).
     * Already on: Cloudflare's ranges are fetched again.
     */
    public function realIpEnable(): JsonResponse
    {
        return $this->change(fn () => $this->firewall->realIpEnable());
    }

    public function realIpRefresh(): JsonResponse
    {
        return $this->change(fn () => $this->firewall->realIpRefresh());
    }

    /**
     * Back to the address of each connection (`cipi nginx real-ip off`).
     *
     * Not when this request itself came through Cloudflare: from then on the
     * API would see Cloudflare's address for the panel — out of its own IP
     * whitelist, and an address fail2ban or CrowdSec may ban. That is turned
     * off at the server, where the panel's connection is not at stake.
     */
    public function realIpDisable(Request $request): JsonResponse
    {
        if ($request->headers->has('CF-Connecting-IP')) {
            return response()->json([
                'error' => 'This request came through Cloudflare: turned off, the API would see Cloudflare instead of the panel, which can lock the panel out of this server. Turn it off at the server: cipi nginx real-ip off',
            ], 409);
        }

        return $this->change(fn () => $this->firewall->realIpDisable());
    }

    /** A change, then the firewall as it is: `{data, message}`; the CLI's refusal is a 422 with its words. */
    protected function change(callable $run): JsonResponse
    {
        try {
            $message = $run();
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        try {
            $data = $this->firewall->status();
        } catch (\RuntimeException) {
            $data = null;
        }

        return response()->json(['data' => $data, 'message' => $message], 200);
    }

    /** null: not given; false: not an address. */
    protected function optionalAddress(mixed $value): string|null|false
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || $value === 'any') {
            return null;
        }

        return $this->firewall->isAddress($value) ? $value : false;
    }

    protected function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'error' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
