<?php

namespace CipiApi\Services;

/**
 * `cipi sync` for a panel (Cipi ≥ 5.18.0): moving apps from this server to
 * another one, with their settings, .env, and — when asked — their databases
 * and storage.
 *
 * A transfer is `cipi sync push … --import --auto-passphrase`: this server
 * exports the apps into an archive, encrypts it with a passphrase it makes
 * for that one transfer, sends it over SSH and has the target import it. The
 * passphrase never goes through the API: nothing here takes one, returns one
 * or queues one.
 *
 * The target must accept this server's sync key (the key of its `cipi` user).
 * `key()` gives it; `trust()` and `untrust()` are what the *target's* API
 * does with it; `check()` says whether a target accepts it.
 *
 * `sync-manage` is as strong as `ssh-manage`. Whoever holds it on a server
 * can have that server send every app — code, .env, databases — to a host of
 * their choosing, and can let another server's `cipi` user sign in here.
 */
class CipiSyncService
{
    public const MIN_CIPI = '5.18.0';

    /** What `cipi sync check` takes as a target: an address or a hostname. */
    public const HOST_RE = '/^[A-Za-z0-9]([A-Za-z0-9.:-]{0,252}[A-Za-z0-9])?$/';

    public const USER_RE = '/^[a-z_][a-z0-9_-]{0,31}$/';

    /** One line: a type, the key, and a comment made of what a hostname or an address is made of. */
    public const KEY_RE = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) ([A-Za-z0-9+\/]{40,1024}={0,2})(?: ([A-Za-z0-9@._:+-]{1,128}))?$/';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
        protected ?CipiVersionService $version = null,
    ) {}

    /**
     * This server's sync key, to be trusted by a target.
     *
     * @return array{key: string, type: string, fingerprint: string, comment: ?string}
     */
    public function key(): array
    {
        $this->assertSupported();
        $result = $this->cli->run('sync pubkey --raw');
        $key = self::normalizeKey($this->plain($result['output'] ?? ''));
        if (($result['exit_code'] ?? 1) !== 0 || $key === null) {
            throw new \RuntimeException('This server has no sync key (cipi sync pubkey)');
        }

        return self::describeKey($key);
    }

    /**
     * Whether this server can push to a target: its key accepted there, Cipi
     * answering. Never throws for a target that says no — that is the answer.
     *
     * @return array<string, mixed>
     */
    public function check(string $host, int $port = 22, string $user = 'cipi'): array
    {
        $this->assertSupported();
        $this->assertTarget($host, $port, $user);
        $result = $this->cli->run('sync check' . $this->targetFlags($host, $port, $user) . ' --json');
        $plain = $this->plain($result['output'] ?? '');
        $data = preg_match('/\{.*\}/s', $plain, $m) ? json_decode($m[0], true) : null;
        if (! is_array($data) || ! array_key_exists('ok', $data)) {
            throw new \RuntimeException($plain !== '' ? $plain : 'cipi sync check gave no answer');
        }

        return [
            'ok' => (bool) $data['ok'],
            'host' => (string) ($data['host'] ?? $host),
            'port' => (int) ($data['port'] ?? $port),
            'user' => (string) ($data['user'] ?? $user),
            'cipi' => is_string($data['cipi'] ?? null) ? $data['cipi'] : null,
            'auto_passphrase' => (bool) ($data['auto_passphrase'] ?? false),
            'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
            'message' => is_string($data['message'] ?? null) ? $data['message'] : null,
        ];
    }

    /**
     * Let the server whose key this is sign in here as `cipi`, which is what
     * its `cipi sync push` needs.
     *
     * @return array{key: string, type: string, fingerprint: string, comment: ?string, changed: bool}
     */
    public function trust(string $key): array
    {
        return $this->changeTrust('trust', $key);
    }

    /**
     * Take that key back out.
     *
     * @return array{key: string, type: string, fingerprint: string, comment: ?string, changed: bool}
     */
    public function untrust(string $key): array
    {
        return $this->changeTrust('untrust', $key);
    }

    /**
     * The command of a transfer, for the job queue. No passphrase in it: the
     * CLI makes one.
     *
     * @param  list<string>  $apps  empty: every app of the server
     */
    public function pushCommand(string $host, int $port, string $user, array $apps, bool $withDb, bool $withStorage): string
    {
        $this->assertSupported();
        $this->assertTarget($host, $port, $user);
        $args = ['sync push'];
        foreach (array_values(array_unique($apps)) as $app) {
            if (! is_string($app) || ! $this->validator->appExists($app)) {
                throw new \InvalidArgumentException('App ' . (is_string($app) ? "'{$app}'" : '(not a name)') . ' not found');
            }
            $args[] = escapeshellarg($app);
        }

        return implode(' ', $args) . $this->targetFlags($host, $port, $user) . ' --import --auto-passphrase'
            . ($withDb ? ' --with-db' : '') . ($withStorage ? ' --with-storage' : '');
    }

    /** A public key as one clean line, or null when it is not one. */
    public static function normalizeKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '' || preg_match('/[\r\n]/', $key) || ! preg_match(self::KEY_RE, $key, $m)) {
            return null;
        }
        $blob = base64_decode($m[2], true);
        // The blob starts with its own type: what makes "ssh-ed25519 <an rsa key>" not a key.
        if ($blob === false || strlen($blob) < 8 || substr($blob, 4, strlen($m[1])) !== $m[1]) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . (isset($m[3]) && $m[3] !== '' ? ' ' . $m[3] : '');
    }

    /**
     * @return array{key: string, type: string, fingerprint: string, comment: ?string}
     */
    public static function describeKey(string $key): array
    {
        [$type, $blob] = explode(' ', $key, 3);
        $comment = explode(' ', $key, 3)[2] ?? null;

        return [
            'key' => $key,
            'type' => $type,
            'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) base64_decode($blob, true), true)), '='),
            'comment' => $comment,
        ];
    }

    /**
     * @return array{key: string, type: string, fingerprint: string, comment: ?string}
     */
    protected function changeTrust(string $verb, string $key): array
    {
        $this->assertSupported();
        $clean = self::normalizeKey($key);
        if ($clean === null) {
            throw new \InvalidArgumentException('Not a public key: one line — ssh-ed25519, ssh-rsa or ecdsa-sha2-nistp*, the key, and at most a comment');
        }
        $result = $this->cli->run("sync {$verb} --key=" . escapeshellarg($clean));
        if (($result['exit_code'] ?? 1) !== 0) {
            $detail = $this->plain($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : "cipi sync {$verb} failed");
        }

        // Whether this call changed anything: a caller that added a trust for one
        // transfer takes back only what it added.
        $said = strtolower($this->plain($result['output'] ?? ''));

        return self::describeKey($clean) + [
            'changed' => $verb === 'trust' ? ! str_contains($said, 'already trusted') : ! str_contains($said, 'nothing to remove'),
        ];
    }

    protected function assertTarget(string $host, int $port, string $user): void
    {
        if (! preg_match(self::HOST_RE, $host)) {
            throw new \InvalidArgumentException("Invalid host '{$host}': an IP address or a hostname");
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid SSH port');
        }
        if (! preg_match(self::USER_RE, $user)) {
            throw new \InvalidArgumentException("Invalid SSH user '{$user}'");
        }
    }

    protected function targetFlags(string $host, int $port, string $user): string
    {
        return ' --host=' . escapeshellarg($host) . ' --port=' . escapeshellarg((string) $port) . ' --user=' . escapeshellarg($user);
    }

    protected function assertSupported(): void
    {
        if ($this->version !== null && ! $this->version->atLeast(self::MIN_CIPI)) {
            throw new SyncUnsupportedException('Transfers from the panel need Cipi ' . self::MIN_CIPI . '+ on the server (cipi self-update)');
        }
    }

    protected function plain(string $output): string
    {
        return trim(preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output);
    }
}
