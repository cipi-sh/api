<?php

namespace CipiApi\Services;

/**
 * Metrics & health history (`cipi metrics`, Cipi CLI ≥ 5.11.0).
 *
 * The core collects every minute into SQLite on the server — host CPU,
 * memory, swap, disk, load, network; per app CPU, memory, PHP-FPM children
 * and max_children hits, requests and 5xx per minute, queue workers, the
 * healthcheck's status and time — and keeps raw minutes 7 days, rollups 30
 * days, events 90 days by default. Every call here reads that database
 * through the CLI and is synchronous: they answer in well under a second.
 *
 * What is not here, on purpose: `cipi metrics openmetrics` (it opens a port
 * and firewall rules), `prune` and `reset` — host CLI only.
 */
class CipiMetricsService
{
    public const MIN_CIPI = '5.11.0';

    public const HOST_METRICS = ['cpu', 'load1', 'mem', 'mem_used_mb', 'swap', 'disk', 'net_rx', 'net_tx'];

    public const APP_METRICS = ['cpu', 'mem_mb', 'procs', 'fpm_procs', 'fpm_max', 'fpm_saturated', 'req', 'http_5xx', 'http_ms', 'http_ok', 'http_code', 'workers_up', 'workers_total'];

    public const SOURCES = ['monitor', 'health', 'deploy', 'heartbeat'];

    /** 90m, 24h, 7d — the CLI caps a range at the rollup retention. */
    public const RANGE_PATTERN = '/^[1-9][0-9]{0,3}[mhd]$/';

    public const SERIES_RULES = [
        'app' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
        'metrics' => ['sometimes', 'nullable', 'string', 'max:300', 'regex:/^[a-z0-9_]+(,[a-z0-9_]+)*$/'],
        'range' => ['sometimes', 'nullable', 'string', 'regex:' . self::RANGE_PATTERN],
        'from' => ['sometimes', 'nullable', 'integer', 'min:1000000000', 'max:99999999999'],
        'to' => ['sometimes', 'nullable', 'integer', 'min:1000000000', 'max:99999999999'],
    ];

    public const UPTIME_RULES = [
        'app' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
        'range' => ['sometimes', 'nullable', 'string', 'regex:' . self::RANGE_PATTERN],
    ];

    public const EVENTS_RULES = [
        'app' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
        'source' => 'sometimes|nullable|string|in:monitor,health,deploy,heartbeat',
        'range' => ['sometimes', 'nullable', 'string', 'regex:' . self::RANGE_PATTERN],
        'limit' => 'sometimes|nullable|integer|min:1|max:1000',
    ];

    /** The CLI's own bounds (cipi metrics retention). */
    public const SETTINGS_RULES = [
        'enabled' => 'sometimes|boolean',
        'probe' => 'sometimes|boolean',
        'raw_days' => 'sometimes|integer|min:1|max:90',
        'rollup_days' => 'sometimes|integer|min:1|max:400',
        'resolution_minutes' => 'sometimes|integer|in:5,10,15,30,60',
        'events_days' => 'sometimes|integer|min:7|max:730',
    ];

    public const HEARTBEAT_RULES = [
        'url' => ['required', 'string', 'max:500', 'regex:/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]+)?(\/[A-Za-z0-9._~:\/?#@!$&\'()*+,;=%-]*)?$/'],
        'every' => 'sometimes|integer|min:1|max:60',
    ];

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /** Latest values, last-hour trend per app, uptime 24h/7d/30d, recent events, collector state. */
    public function overview(): array
    {
        return $this->json('metrics overview --json');
    }

    /** Collector, database size and rows, retention, heartbeat (by host), OpenMetrics (no token). */
    public function status(): array
    {
        return $this->json('metrics status --json');
    }

    /**
     * {scope, metrics, from, to, step, source (raw|rollup), series: {metric: [[ts, avg, max], …]}}
     *
     * @param  array{app?: ?string, metrics?: ?string, range?: ?string, from?: ?int, to?: ?int}  $input
     */
    public function series(array $input): array
    {
        return $this->json($this->seriesCommand($input));
    }

    public function seriesCommand(array $input): string
    {
        $parts = ['metrics show'];
        $app = $input['app'] ?? null;
        if ($app) {
            $parts[] = '--app=' . escapeshellarg($app);
        }
        $known = $app ? self::APP_METRICS : self::HOST_METRICS;
        if (! empty($input['metrics'])) {
            $wanted = array_values(array_intersect(explode(',', $input['metrics']), $known));
            if ($wanted !== []) {
                $parts[] = '--metrics=' . escapeshellarg(implode(',', $wanted));
            }
        }
        if (! empty($input['from'])) {
            $parts[] = '--from=' . (int) $input['from'];
            if (! empty($input['to'])) {
                $parts[] = '--to=' . (int) $input['to'];
            }
        } elseif (! empty($input['range'])) {
            $parts[] = '--range=' . escapeshellarg($input['range']);
        }
        $parts[] = '--json';

        return implode(' ', $parts);
    }

    /** Error for series input the CLI would refuse, or null. */
    public function seriesError(array $input): ?string
    {
        if (! empty($input['metrics'])) {
            $known = ! empty($input['app']) ? self::APP_METRICS : self::HOST_METRICS;
            $unknown = array_diff(explode(',', $input['metrics']), $known);
            if ($unknown !== []) {
                return 'Unknown metric(s): ' . implode(', ', $unknown) . '. ' . (! empty($input['app']) ? 'App' : 'Host') . ': ' . implode(', ', $known);
            }
        }
        if (! empty($input['to']) && empty($input['from'])) {
            return 'to needs from';
        }
        if (! empty($input['from']) && ! empty($input['to']) && (int) $input['to'] <= (int) $input['from']) {
            return 'to must be after from';
        }

        return null;
    }

    /** One app: {app, uptime, checks, down_minutes, avg_ms, incidents, days, events}; all: {range, apps: […]}. */
    public function uptime(?string $app, ?string $range): array
    {
        $cmd = 'metrics uptime' . ($app ? ' ' . escapeshellarg($app) : '')
            . ($range ? ' --range=' . escapeshellarg($range) : '') . ' --json';

        return $this->json($cmd);
    }

    /** {from, to, events: [{ts, source, subject, status, summary}, …]}, newest first. */
    public function events(array $input): array
    {
        $parts = ['metrics events'];
        if (! empty($input['app'])) {
            $parts[] = '--app=' . escapeshellarg($input['app']);
        }
        if (! empty($input['source'])) {
            $parts[] = '--source=' . escapeshellarg($input['source']);
        }
        if (! empty($input['range'])) {
            $parts[] = '--range=' . escapeshellarg($input['range']);
        }
        if (! empty($input['limit'])) {
            $parts[] = '--limit=' . (int) $input['limit'];
        }
        $parts[] = '--json';

        return $this->json(implode(' ', $parts));
    }

    /** Commands for validated settings input, in the order they run. */
    public function settingsCommands(array $input): array
    {
        $cmds = [];
        if (array_key_exists('enabled', $input)) {
            $cmds[] = 'metrics ' . ($input['enabled'] ? 'enable' : 'disable');
        }
        if (array_key_exists('probe', $input)) {
            $cmds[] = 'metrics probe ' . ($input['probe'] ? 'on' : 'off');
        }
        $ret = [];
        foreach (['raw_days' => 'raw', 'rollup_days' => 'rollup', 'events_days' => 'events'] as $k => $flag) {
            if (isset($input[$k])) {
                $ret[] = "--{$flag}=" . (int) $input[$k] . 'd';
            }
        }
        if (isset($input['resolution_minutes'])) {
            $ret[] = '--resolution=' . (int) $input['resolution_minutes'] . 'm';
        }
        if ($ret !== []) {
            $cmds[] = 'metrics retention ' . implode(' ', $ret);
        }

        return $cmds;
    }

    public function settingsError(array $input): ?string
    {
        if ($input === []) {
            return 'Nothing to change: enabled, probe, raw_days, rollup_days, resolution_minutes, events_days';
        }
        if (isset($input['raw_days'], $input['rollup_days']) && $input['rollup_days'] < $input['raw_days']) {
            return 'rollup_days cannot be shorter than raw_days';
        }

        return null;
    }

    /**
     * Applies validated settings and returns the new status.
     *
     * @throws \RuntimeException with the CLI's reason when one step fails (the earlier ones stay applied)
     */
    public function updateSettings(array $input): array
    {
        foreach ($this->settingsCommands($input) as $cmd) {
            $this->runOk($cmd);
        }

        return $this->status();
    }

    public function heartbeatCommand(string $url, ?int $every): string
    {
        return 'metrics heartbeat set ' . escapeshellarg($url) . ' --every=' . (int) ($every ?? 1);
    }

    /** @return array heartbeat status (URL by host only) */
    public function heartbeatSet(string $url, ?int $every): array
    {
        $this->runOk($this->heartbeatCommand($url, $every));

        return $this->status()['heartbeat'] ?? [];
    }

    public function heartbeatOff(): array
    {
        $this->runOk('metrics heartbeat off');

        return $this->status()['heartbeat'] ?? [];
    }

    /** {host, code, ok} — one call now. */
    public function heartbeatTest(): array
    {
        $this->version->require(self::MIN_CIPI, 'Metrics');
        $result = $this->cli->run('metrics heartbeat test --json');
        $decoded = CipiAppJitService::decode((string) ($result['output'] ?? ''));
        if ($decoded === null) {
            throw new \RuntimeException($this->firstLine((string) ($result['output'] ?? ''), 'cipi metrics heartbeat test failed'));
        }

        return $decoded;
    }

    /** `cipi basicauth agent <app> status` as {mode, prefix}. */
    public function basicAuthAgent(string $app): array
    {
        $this->version->require(self::MIN_CIPI, 'cipi/agent routes behind basic auth');
        $result = $this->cli->run('basicauth agent ' . escapeshellarg($app) . ' status');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine((string) $result['output'], 'cipi basicauth agent failed'));
        }

        return self::parseAgentStatus((string) $result['output']);
    }

    public static function parseAgentStatus(string $output): array
    {
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $output);
        if (preg_match('#\((/[A-Za-z0-9_/-]+/)\): public#', $plain, $m)) {
            return ['mode' => 'public', 'public' => true, 'prefix' => $m[1]];
        }
        if (str_contains($plain, 'behind basic auth')) {
            return ['mode' => 'protected', 'public' => false, 'prefix' => null];
        }

        return ['mode' => 'public', 'public' => false, 'prefix' => null];
    }

    public function basicAuthAgentSet(string $app, string $mode): array
    {
        $this->version->require(self::MIN_CIPI, 'cipi/agent routes behind basic auth');
        $this->runOk('basicauth agent ' . escapeshellarg($app) . ' ' . ($mode === 'protected' ? 'protected' : 'public'));

        return $this->basicAuthAgent($app);
    }

    /**
     * @throws \RuntimeException on an older core, a CLI failure or output that is not JSON
     */
    protected function json(string $command): array
    {
        $this->version->require(self::MIN_CIPI, 'Metrics');
        $result = $this->cli->run($command);
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine($output, 'cipi ' . strtok($command, ' ') . ' failed'));
        }
        $decoded = self::decode($output);
        if ($decoded === null) {
            throw new \RuntimeException('cipi ' . $command . ' returned no JSON (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    protected function runOk(string $command): void
    {
        $this->version->require(self::MIN_CIPI, 'Metrics');
        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine((string) ($result['output'] ?? ''), 'cipi ' . $command . ' failed'));
        }
    }

    /** JSON object or array from the CLI's output, ignoring a sudo line around it. */
    public static function decode(string $output): ?array
    {
        $output = trim($output);
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($output, $open);
            $end = strrpos($output, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    protected function firstLine(string $output, string $fallback): string
    {
        $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));
        if (preg_match('/^\[ERROR\]\s*(.+)$/m', $plain, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^✗\s*(.+)$/mu', $plain, $m)) {
            return trim($m[1]);
        }

        return $plain !== '' ? mb_substr(strtok($plain, "\n"), 0, 300) : $fallback;
    }
}
