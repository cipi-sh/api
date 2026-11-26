<?php

namespace CipiApi\Services;

/**
 * Notifications (`cipi notification`, Cipi CLI ≥ 5.12.0): every channel —
 * SMTP, Resend, Amazon SES, Slack, Discord, Mattermost, Google Chat,
 * Telegram, ntfy, Pushover, webhook — and the triggers that decide which
 * events reach them. An enabled trigger reaches every enabled channel that
 * takes it: a channel takes every trigger, or (Cipi CLI ≥ 5.15.0) only its
 * `events` — categories (security, deploy, backup…), single triggers, or
 * "urgent" (failures and security events).
 *
 * All synchronous: the CLI edits the encrypted vault and answers at once, so
 * the secrets passed as flags never reach the jobs table. Secrets never come
 * back: a channel reports `token_set`, `api_key_set`, `secret_set`, … and a
 * webhook URL with its secret part masked. An update that leaves a secret out
 * keeps the stored one.
 */
class CipiNotificationsService
{
    public const MIN_CIPI = '5.12.0';

    /** The core that keeps `events` per channel (`--events=`, `categories` in the status). */
    public const EVENTS_MIN_CIPI = '5.15.0';

    /** A selector of a channel's events: all, urgent, a category id (basic-auth) or a trigger id (backup_fail). */
    public const EVENT_PATTERN = '/^[a-z][a-z0-9_-]{1,40}$/';

    public const TYPES = ['smtp', 'resend', 'ses', 'slack', 'discord', 'mattermost', 'googlechat', 'telegram', 'ntfy', 'pushover', 'webhook'];

    public const URL_TYPES = ['slack', 'discord', 'mattermost', 'googlechat', 'ntfy', 'webhook'];

    /** Fields each type takes, and which of them are secrets (optional on update). */
    public const FIELDS = [
        'smtp' => ['host', 'port', 'user', 'password', 'from', 'to', 'tls'],
        'resend' => ['api_key', 'from', 'to'],
        'ses' => ['region', 'access_key', 'secret_key', 'from', 'to'],
        'slack' => ['url'],
        'discord' => ['url'],
        'mattermost' => ['url'],
        'googlechat' => ['url'],
        'telegram' => ['token', 'chat_id'],
        'ntfy' => ['url', 'priority'],
        'pushover' => ['token', 'user'],
        'webhook' => ['url', 'secret'],
    ];

    public const SECRETS = ['password', 'api_key', 'secret_key', 'token', 'secret'];

    /** Required to add a channel of each type (an update needs none). */
    public const REQUIRED = [
        'smtp' => ['host', 'user', 'password', 'from', 'to'],
        'resend' => ['api_key', 'from', 'to'],
        'ses' => ['region', 'access_key', 'secret_key', 'from', 'to'],
        'slack' => ['url'],
        'discord' => ['url'],
        'mattermost' => ['url'],
        'googlechat' => ['url'],
        'telegram' => ['token', 'chat_id'],
        'ntfy' => ['url'],
        'pushover' => ['token', 'user'],
        'webhook' => ['url'],
    ];

    public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    public const TRIGGER_PATTERN = '/^[a-z][a-z0-9_]{1,40}$/';

    /** Shapes the CLI checks again; refused here first, so nothing odd reaches a shell. */
    public const CHANNEL_RULES = [
        'type' => 'sometimes|string|in:smtp,resend,ses,slack,discord,mattermost,googlechat,telegram,ntfy,pushover,webhook',
        'id' => ['sometimes', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
        'host' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
        'port' => 'sometimes|nullable|integer|min:1|max:65535',
        'user' => 'sometimes|nullable|string|max:255',
        'password' => 'sometimes|nullable|string|max:512',
        'from' => 'sometimes|nullable|string|max:255',
        'to' => 'sometimes|nullable|string|max:1000',
        'tls' => 'sometimes|boolean',
        'url' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^https?:\/\/[^\s\'"]+$/'],
        'priority' => 'sometimes|nullable|string|in:min,low,default,high,urgent,1,2,3,4,5',
        'secret' => 'sometimes|nullable|string|max:512',
        'clear_secret' => 'sometimes|boolean',
        'token' => 'sometimes|nullable|string|max:255',
        'chat_id' => ['sometimes', 'nullable', 'string', 'regex:/^-?[0-9]{1,20}$/'],
        'api_key' => 'sometimes|nullable|string|max:255',
        'region' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}(-[a-z]+)+-[0-9]$/'],
        'access_key' => ['sometimes', 'nullable', 'string', 'regex:/^(AKIA|ASIA)[A-Z0-9]{12,}$/'],
        'secret_key' => 'sometimes|nullable|string|max:255',
        'enabled' => 'sometimes|boolean',
        'test' => 'sometimes|boolean',
        // What the channel takes: null or [] or ["all"] — everything; else
        // categories, triggers, "urgent".
        'events' => 'sometimes|nullable|array|max:120',
        'events.*' => ['string', 'regex:/^[a-z][a-z0-9_-]{1,40}$/'],
    ];

    public const TRIGGERS_RULES = [
        'triggers' => 'sometimes|array|max:300',
        'triggers.*' => 'boolean',
        'on' => 'sometimes|array|max:300',
        'on.*' => ['string', 'regex:/^[a-z][a-z0-9_]{1,40}$/'],
        'off' => 'sometimes|array|max:300',
        'off.*' => ['string', 'regex:/^[a-z][a-z0-9_]{1,40}$/'],
        'all' => 'sometimes|boolean',
    ];

    /** The core that writes notifications in a chosen time zone and under a chosen server name. */
    public const SETTINGS_MIN_CIPI = '5.17.0';

    /** What `cipi notification timezone` takes before it looks the zone up on the server. */
    public const TIMEZONE_PATTERN = '/^[A-Za-z][A-Za-z0-9_+-]*(\/[A-Za-z0-9_+-]+){0,2}$/';

    /** What `cipi notification server-name` takes. */
    public const SERVER_NAME_PATTERN = '/^[A-Za-z0-9]([A-Za-z0-9 ._-]{0,38}[A-Za-z0-9._-])?$/';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /**
     * {channels: [...], triggers: {enabled, total}, types: [...]} — no secret in it.
     * From Cipi 5.15.0 every channel has `events` (null: everything), and the
     * status has `categories` [{id, label, triggers}] and `selectors`.
     */
    public function status(): array
    {
        return $this->json('notification status --json');
    }

    /** {triggers: [{id, category, label, enabled}, …], enabled, total} */
    public function triggers(): array
    {
        return $this->json('notification triggers --json');
    }

    /** One channel of the status, or null. */
    public function channel(string $id): ?array
    {
        foreach ($this->status()['channels'] ?? [] as $ch) {
            if (($ch['id'] ?? null) === $id) {
                return $ch;
            }
        }

        return null;
    }

    /** Error for add/update input, or null. $current is the stored channel on update. */
    public function channelError(array $input, ?array $current): ?string
    {
        $type = $current['type'] ?? ($input['type'] ?? null);
        if ($type === null || ! in_array($type, self::TYPES, true)) {
            return 'type: one of ' . implode(', ', self::TYPES);
        }
        if ($current !== null && isset($input['type']) && $input['type'] !== $type) {
            return "The type of '{$current['id']}' is {$type}; remove it and add a new channel to change it";
        }
        $allowed = array_merge(self::FIELDS[$type], ['type', 'id', 'enabled', 'test', 'events'], $type === 'webhook' ? ['clear_secret'] : []);
        $extra = array_diff(array_keys($input), $allowed);
        if ($extra !== []) {
            return 'Not a field of a ' . $type . ' channel: ' . implode(', ', $extra) . ' (' . implode(', ', self::FIELDS[$type]) . ')';
        }
        if ($current === null) {
            if ($type !== 'smtp' && empty($input['id'])) {
                return 'id is required: lowercase letters, digits, dashes (e.g. ops)';
            }
            if (($input['id'] ?? null) === 'smtp' && $type !== 'smtp') {
                return "'smtp' is the id of the SMTP channel";
            }
            $missing = array_values(array_filter(self::REQUIRED[$type], fn ($f) => ! isset($input[$f]) || $input[$f] === ''));
            if ($missing !== []) {
                return 'Required for ' . $type . ': ' . implode(', ', $missing);
            }
        }
        if (isset($input['url']) && $input['url'] !== '' && str_starts_with($input['url'], 'http://') && ! in_array($type, ['ntfy', 'webhook'], true)) {
            return 'url must start with https:// (http:// only for ntfy and webhook)';
        }
        foreach (['from', 'to'] as $f) {
            if (! empty($input[$f]) && ! str_contains($input[$f], '@')) {
                return "{$f}: an email address" . ($f === 'to' ? ' (several, comma-separated)' : '');
            }
        }
        foreach ((array) ($input['events'] ?? []) as $e) {
            if (! is_string($e) || ! preg_match(self::EVENT_PATTERN, $e)) {
                return 'events: categories (security, deploy…), triggers (backup_fail…), urgent or all';
            }
        }

        return null;
    }

    /** `--events=a,b` — or `--events=all` for null, [] or a list with "all". */
    public static function eventsFlag(?array $events): string
    {
        $events = array_values(array_unique(array_filter((array) $events, 'is_string')));
        if ($events === [] || in_array('all', $events, true)) {
            return '--events=all';
        }

        return '--events=' . implode(',', $events);
    }

    /** The CLI arguments for the fields given: --flag='value'. Secrets left empty are left out (kept). */
    public function flags(array $input): array
    {
        $parts = [];
        foreach ($input as $k => $v) {
            if ($k === 'events') {
                $parts[] = self::eventsFlag(is_array($v) ? $v : null);

                continue;
            }
            if (in_array($k, ['type', 'id', 'enabled', 'test', 'clear_secret'], true) || $v === null || $v === '') {
                continue;
            }
            $flag = '--' . str_replace('_', '-', $k);
            if ($k === 'tls') {
                $parts[] = $flag . '=' . ($v ? 'on' : 'off');
            } elseif ($k === 'port') {
                $parts[] = $flag . '=' . (int) $v;
            } else {
                $parts[] = $flag . '=' . escapeshellarg((string) $v);
            }
        }
        if (! empty($input['clear_secret'])) {
            $parts[] = '--clear-secret';
        }

        return $parts;
    }

    public function addCommand(array $input): string
    {
        $type = $input['type'];
        $parts = ['notification add', $type];
        if ($type !== 'smtp') {
            $parts[] = $input['id'];
        }
        $parts = array_merge($parts, $this->flags($input));
        if ($type === 'smtp') {
            if (array_key_exists('enabled', $input)) {
                $parts[] = '--enabled=' . ($input['enabled'] ? 'on' : 'off');
            }
            $parts[] = '--no-test';
        }

        return implode(' ', $parts);
    }

    public function updateCommand(string $id, string $type, array $input): string
    {
        $parts = array_merge(['notification update', $id], $this->flags($input));
        // Only what the channel takes: nothing of SMTP is saved again, nor tested.
        if ($type === 'smtp' && array_keys($input) !== ['events']) {
            $parts[] = '--no-test';
        }

        return implode(' ', $parts);
    }

    /**
     * Adds a channel; enabled: false turns it off right after, test: true sends a test.
     *
     * @return array{channel: ?array, test: ?array}
     */
    public function add(array $input): array
    {
        if (array_key_exists('events', $input)) {
            $this->version->require(self::EVENTS_MIN_CIPI, 'Choosing the events of a channel');
        }
        $this->runOk($this->addCommand($input));
        $id = $input['type'] === 'smtp' ? 'smtp' : $input['id'];
        if ($input['type'] !== 'smtp' && array_key_exists('enabled', $input) && ! $input['enabled']) {
            $this->runOk('notification disable ' . $id);
        }

        return ['channel' => $this->channel($id), 'test' => ! empty($input['test']) ? $this->test($id) : null];
    }

    /** @return array{channel: ?array, test: ?array} */
    public function update(string $id, string $type, array $input): array
    {
        $changes = array_diff_key($input, array_flip(['enabled', 'test']));
        if (array_key_exists('events', $changes)) {
            $this->version->require(self::EVENTS_MIN_CIPI, 'Choosing the events of a channel');
        }
        if ($changes !== []) {
            $this->runOk($this->updateCommand($id, $type, $changes));
        }
        if (array_key_exists('enabled', $input)) {
            $this->runOk('notification ' . ($input['enabled'] ? 'enable ' : 'disable ') . $id);
        }

        return ['channel' => $this->channel($id), 'test' => ! empty($input['test']) ? $this->test($id) : null];
    }

    public function remove(string $id): void
    {
        $this->runOk('notification remove ' . $id . ($id === 'smtp' ? ' --force' : ''));
    }

    public function setEnabled(string $id, bool $enabled): ?array
    {
        $this->runOk('notification ' . ($enabled ? 'enable ' : 'disable ') . $id);

        return $this->channel($id);
    }

    /** {ok, message}: ok false when the channel refused it (the CLI's reason in message). */
    public function test(string $id): array
    {
        $this->version->require(self::MIN_CIPI, 'Notifications');
        $result = $this->cli->run('notification test ' . $id);
        $out = (string) ($result['output'] ?? '');

        return [
            'ok' => $result['exit_code'] === 0,
            'message' => $result['exit_code'] === 0 ? $this->lastLine($out, 'Test sent') : $this->firstLine($out, 'Test failed'),
        ];
    }

    /** Commands for validated trigger input: all on/off, then the changes in one write. */
    public function triggersCommands(array $input): array
    {
        $cmds = [];
        if (array_key_exists('all', $input)) {
            $cmds[] = 'notification triggers ' . ($input['all'] ? 'enable-all' : 'disable-all');
        }
        $on = array_values(array_unique($input['on'] ?? []));
        $off = array_values(array_unique($input['off'] ?? []));
        foreach ($input['triggers'] ?? [] as $t => $state) {
            if ($state) {
                $on[] = $t;
            } else {
                $off[] = $t;
            }
        }
        $on = array_values(array_unique($on));
        $off = array_values(array_diff(array_unique($off), $on));
        $flags = [];
        if ($on !== []) {
            $flags[] = '--on=' . implode(',', $on);
        }
        if ($off !== []) {
            $flags[] = '--off=' . implode(',', $off);
        }
        if ($flags !== []) {
            $cmds[] = 'notification triggers set ' . implode(' ', $flags);
        }

        return $cmds;
    }

    public function triggersError(array $input): ?string
    {
        foreach (array_keys($input['triggers'] ?? []) as $t) {
            if (! preg_match(self::TRIGGER_PATTERN, (string) $t)) {
                return "Not a trigger: {$t}";
            }
        }
        if ($this->triggersCommands($input) === []) {
            return 'Nothing to change: triggers ({id: bool}), on, off or all';
        }

        return null;
    }

    public function updateTriggers(array $input): array
    {
        foreach ($this->triggersCommands($input) as $cmd) {
            $this->runOk($cmd);
        }

        return $this->triggers();
    }

    public function resetTriggers(): array
    {
        $this->runOk('notification triggers reset');

        return $this->triggers();
    }

    /**
     * How notifications read (Cipi 5.17.0): the time zone of their `Time:`
     * line (`timezone`, null: the server's own, `server_timezone`) and the
     * name they give the server (`server_name`, null: its `hostname`). `now`
     * is the time as a notification would write it at this moment.
     *
     * @return array{timezone: ?string, server_timezone: string, server_name: ?string, hostname: string, now: string}
     */
    public function settings(): array
    {
        return $this->settingsJson('notification timezone --json');
    }

    /** Error for settings input, or null. */
    public function settingsError(array $input): ?string
    {
        if (! array_key_exists('timezone', $input) && ! array_key_exists('server_name', $input)) {
            return 'Give timezone, server_name or both (null puts the server\'s own back)';
        }
        $tz = $input['timezone'] ?? null;
        if ($tz !== null && $tz !== '' && preg_match(self::TIMEZONE_PATTERN, $tz) !== 1) {
            return 'timezone: an IANA name such as Europe/Rome, America/New_York or UTC — or null for the server\'s own';
        }
        $name = $input['server_name'] ?? null;
        if ($name !== null && $name !== '' && preg_match(self::SERVER_NAME_PATTERN, $name) !== 1) {
            return 'server_name: letters, digits, spaces, dots, dashes and underscores, 40 characters at most — or null for the hostname';
        }

        return null;
    }

    /**
     * Set what is given; null (or "") puts the server's own time zone, or the
     * hostname, back. Answers with the settings after the change.
     */
    public function updateSettings(array $input): array
    {
        $settings = null;
        if (array_key_exists('timezone', $input)) {
            $tz = (string) ($input['timezone'] ?? '');
            $settings = $this->settingsJson('notification timezone ' . ($tz === '' ? 'server' : escapeshellarg($tz)) . ' --json');
        }
        if (array_key_exists('server_name', $input)) {
            $name = (string) ($input['server_name'] ?? '');
            $settings = $this->settingsJson('notification server-name ' . ($name === '' ? '--clear' : escapeshellarg($name)) . ' --json');
        }

        return $settings ?? $this->settings();
    }

    protected function settingsJson(string $command): array
    {
        $this->version->require(self::SETTINGS_MIN_CIPI, 'The time zone and the server name of notifications');
        $result = $this->cli->run($command);
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));
            if (preg_match('/sudo: .*(not allowed|a password is required|a terminal is required)/i', $plain)) {
                throw new \RuntimeException('The time zone and the server name of notifications needs Cipi ' . self::SETTINGS_MIN_CIPI . '+ on this server and its API sudoers (cipi self-update, then cipi api update).');
            }

            throw new \RuntimeException($this->firstLine($output, 'cipi notification settings failed'));
        }
        $decoded = CipiMetricsService::decode($output);
        if ($decoded === null || ! array_key_exists('server_timezone', $decoded)) {
            throw new \RuntimeException('cipi notification returned no settings (the time zone and server name needs Cipi ' . self::SETTINGS_MIN_CIPI . '+)');
        }

        return $decoded;
    }

    protected function json(string $command): array
    {
        $this->version->require(self::MIN_CIPI, 'Notifications');
        $result = $this->cli->run($command);
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine($output, 'cipi ' . $command . ' failed'));
        }
        $decoded = CipiMetricsService::decode($output);
        if ($decoded === null) {
            throw new \RuntimeException('cipi ' . $command . ' returned no JSON (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    protected function runOk(string $command): void
    {
        $this->version->require(self::MIN_CIPI, 'Notifications');
        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine((string) ($result['output'] ?? ''), 'cipi ' . strtok($command, '=') . ' failed'));
        }
    }

    protected function firstLine(string $output, string $fallback): string
    {
        $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));
        if (preg_match('/^\[ERROR\]\s*(.+)$/m', $plain, $m)) {
            return trim($m[1]);
        }

        return $plain !== '' ? mb_substr(strtok($plain, "\n"), 0, 300) : $fallback;
    }

    protected function lastLine(string $output, string $fallback): string
    {
        $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $plain))));
        $last = $lines !== [] ? end($lines) : '';

        return $last !== '' ? ltrim($last, '✓ ') : $fallback;
    }
}
