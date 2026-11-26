<?php

namespace CipiApi\Services;

/**
 * Valkey per app (`cipi valkey`, in the core since 5.9.0; from the API with
 * Cipi CLI ≥ 5.17.0, whose sudoers carry the commands).
 *
 * A Laravel app keeps cache, sessions and queues in Valkey. On the server's
 * shared instance every app holds the same password, so each one can read and
 * write the others' keys; an app with its own instance (its own port and
 * password, its own memory limit) cannot. `isolate` gives an app — or every
 * app still on the shared one — its instance: its keys are copied with the app
 * in maintenance mode for the seconds that takes, its .env is switched and its
 * workers restarted. `share` moves it back. Once no app is left on the shared
 * instance, `flush-shared` empties it and changes its password.
 *
 * No password leaves the server through here: the list has none, and what
 * `flush-shared` prints (the new shared password, once) is never returned.
 */
class CipiValkeyCliService
{
    public const MIN_CIPI = '5.17.0';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
        protected CipiOutputParser $parser,
    ) {}

    /**
     * @return array{shared_port: int, apps: list<array{app: string, uses: string, port: ?int, instance: ?string, memory: ?string, keys: ?int}>,
     *               shared: list<string>, isolated: int}
     */
    public function list(): array
    {
        $this->version->require(self::MIN_CIPI, 'Valkey per app');

        $result = $this->cli->run('valkey list --json');
        $plain = $this->plain($result);
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->unsupported($plain) ?? $this->firstError($plain) ?? 'cipi valkey list failed');
        }
        $decoded = CipiAppJitService::decode((string) ($result['output'] ?? ''));
        if ($decoded === null || ! isset($decoded['apps']) || ! is_array($decoded['apps'])) {
            throw new \RuntimeException('cipi valkey list returned no list (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        $apps = array_values($decoded['apps']);
        $shared = array_values(array_map(
            fn ($a) => (string) $a['app'],
            array_filter($apps, fn ($a) => ($a['uses'] ?? null) === 'shared'),
        ));

        return [
            'shared_port' => (int) ($decoded['shared_port'] ?? 6379),
            'apps' => $apps,
            // Who can still read each other's cache, sessions and queues.
            'shared' => $shared,
            'isolated' => count(array_filter($apps, fn ($a) => ($a['uses'] ?? null) === 'own')),
        ];
    }

    /** One app of the list, or null (a custom or Node app has no Valkey of Cipi's). */
    public function app(string $app): ?array
    {
        foreach ($this->list()['apps'] as $row) {
            if (($row['app'] ?? null) === $app) {
                return $row;
            }
        }

        return null;
    }

    /**
     * `cipi valkey isolate <app>|--all --yes [--no-copy]`. Without the copy the
     * app starts with an empty cache, no sessions and no queued jobs.
     */
    public function isolateCommand(?string $app, bool $copy = true): string
    {
        $this->version->require(self::MIN_CIPI, 'Valkey per app');

        return 'valkey isolate ' . ($app === null ? '--all' : escapeshellarg($app)) . ' --yes' . ($copy ? '' : ' --no-copy');
    }

    /** `cipi valkey share <app> --yes [--no-copy]` — back to the shared instance; its own is removed. */
    public function shareCommand(string $app, bool $copy = true): string
    {
        $this->version->require(self::MIN_CIPI, 'Valkey per app');

        return 'valkey share ' . escapeshellarg($app) . ' --yes' . ($copy ? '' : ' --no-copy');
    }

    /** Restart an app's own instance; it saves on the way down and loads on the way up. */
    public function restart(string $app): array
    {
        $this->version->require(self::MIN_CIPI, 'Valkey per app');

        $result = $this->cli->run('valkey restart ' . escapeshellarg($app));
        $plain = $this->plain($result);
        if ($result['exit_code'] !== 0) {
            if ($unsupported = $this->unsupported($plain)) {
                throw new \RuntimeException($unsupported);
            }
            if (str_contains($plain, 'an app with its own instance')) {
                throw new ValkeyRefusedException("'{$app}' has no Valkey instance of its own");
            }

            throw new \RuntimeException($this->firstError($plain) ?? 'cipi valkey restart failed');
        }

        return $this->list();
    }

    /**
     * Empty the shared instance and change its password. Refused while an app
     * still uses it. The CLI prints the new password: it stays on the server
     * (`cipi credentials`), and nothing of that output is returned or kept.
     */
    public function flushShared(): array
    {
        $this->version->require(self::MIN_CIPI, 'Valkey per app');

        $result = $this->cli->run('valkey flush-shared --yes');
        if ($result['exit_code'] !== 0) {
            $plain = $this->plain($result);
            if ($unsupported = $this->unsupported($plain)) {
                throw new \RuntimeException($unsupported);
            }
            // Only the CLI's own error lines: nothing else of this output is safe to show.
            if (preg_match('/Still in use by: ([a-z0-9 ]+?) —/', $plain, $m)) {
                throw new ValkeyRefusedException('The shared Valkey is still in use by: ' . trim($m[1]) . ' — give them their own instance first');
            }
            if (str_contains($plain, 'FLUSHALL was refused')) {
                throw new \RuntimeException('The shared Valkey did not answer: is valkey-server running?');
            }

            throw new \RuntimeException('cipi valkey flush-shared failed — run it at the server to see why');
        }

        return $this->list() + ['flushed' => true];
    }

    protected function unsupported(string $detail): ?string
    {
        return preg_match('/sudo: .*(not allowed|a password is required|a terminal is required)|Unknown command/i', $detail)
            ? 'This server cannot manage Valkey from the API yet: Cipi ' . self::MIN_CIPI . '+ is required (cipi self-update, then cipi api update).'
            : null;
    }

    protected function firstError(string $plain): ?string
    {
        if (preg_match('/^\s*(?:\[ERROR\]|✗|✖|Error:)\s*(.+)$/mu', $plain, $m)) {
            return mb_substr(trim($m[1]), 0, 400);
        }
        $line = trim((string) strtok($plain, "\n"));

        return $line !== '' ? mb_substr($line, 0, 400) : null;
    }

    /** @param  array{output?: string}  $result */
    protected function plain(array $result): string
    {
        return trim($this->parser->stripAnsi((string) ($result['output'] ?? '')));
    }
}
