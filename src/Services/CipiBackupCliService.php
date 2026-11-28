<?php

namespace CipiApi\Services;

/**
 * Backup profiles (Cipi CLI ≥ 5.9.0 — the JSON outputs, `run --detach` and
 * the panel sudoers all arrive with it).
 *
 * What is here: the status a backup page is drawn from, the runs each
 * destination holds, a run started in the background, verify, and the
 * profiles themselves (add, edit, remove, enable, disable).
 *
 * What is not, on purpose: `cipi backup configure` and `cipi backup
 * destination add|edit|remove`. They take an S3 secret key, and the only way
 * this API hands a value to the CLI is the command line — readable by every
 * local user for as long as the command runs, and stored with the job. The
 * destinations are created on the server; the panel uses them by name.
 * `fetch`, `key` and `prune` stay on the host CLI too.
 */
class CipiBackupCliService
{
    public const MIN_CIPI = '5.9.0';

    /** What `cipi backup configure` writes and `cipi backup status` looks for. */
    public const CONFIG_FILE = '/etc/cipi/backup.json';

    public const SCOPES = ['all', 'files', 'db'];

    /** exclude_paths: an older core would take the flag and ignore it. */
    public const MIN_CIPI_EXCLUDE_PATHS = '5.13.0';

    /** Same shapes the CLI and cipi.yml accept. */
    protected const NAME_RE = '/^[a-z][a-z0-9-]{1,31}$/';

    protected const GLOB_RE = '/^[a-zA-Z0-9_.*?\[\]-]{1,64}$/';

    protected const TABLE_GLOB_RE = '/^[a-zA-Z0-9_.*?\[\]-]{1,128}$/';

    /**
     * A path left out of the app archives (Cipi 5.13.0): a tar exclusion
     * pattern relative to the app, optionally "<apps>:" first. Never absolute.
     */
    protected const PATH_RE = '/^(?:[a-z0-9*?-]+:)?(?!\/)[A-Za-z0-9._*?\/@+ \[\]-]{1,255}$/';

    /** Patterns that would leave the whole app out of its archive. */
    protected const PATH_WHOLE_APP = ['*', '*/', 'shared', 'htdocs'];

    protected const EVERY_RE = '/^[1-9][0-9]{0,2}[mhd]$/';

    protected const CRON_RE = '/^[0-9*\/,-]+( [0-9*\/,-]+){4}$/';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /** @throws \RuntimeException on an older core */
    public function requireCore(): void
    {
        $this->version->require(self::MIN_CIPI, 'Backup management from the panel');
    }

    public function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_RE, $name);
    }

    /**
     * `cipi backup status --json`: destinations (no keys), profiles with what
     * each covers now, its last run, overdue, running; apps and databases no
     * enabled profile takes. Read-only on the server.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function status(): array
    {
        $this->requireCore();

        $status = $this->json('backup status --json', 'cipi backup status');

        // "Not configured" is the CLI's to say, in so many words. An answer
        // that is JSON but carries no `configured` (an empty object, another
        // command's output) is not that answer: a client would draw a server
        // without backups out of it.
        if (! is_bool($status['configured'] ?? null)) {
            throw new \RuntimeException('cipi backup status --json answered without a backup status (no "configured" in it). Read it on the server: sudo -u www-data sudo -n /usr/local/bin/cipi backup status --json');
        }

        // Where the answer comes from, for whoever reads "not configured" on
        // a server they configured: which host, and the file the CLI looks for.
        $host = gethostname();

        return $status + [
            'host' => is_string($host) && $host !== '' ? $host : null,
            'config_file' => self::CONFIG_FILE,
        ];
    }

    /**
     * `cipi backup list --json [--profile=]`: the runs each destination holds.
     * Lists every bucket: it can take a few seconds.
     *
     * @return array{profiles: array<string, list<array{run: string, destinations: list<string>}>>, errors: list<string>}
     *
     * @throws \RuntimeException
     */
    public function runs(?string $profile = null): array
    {
        $this->requireCore();
        $command = 'backup list --json';
        if ($profile !== null && $profile !== '') {
            $command .= ' --profile=' . escapeshellarg($profile);
        }
        $decoded = $this->json($command, 'cipi backup list');
        if (isset($decoded['profiles']) && $decoded['profiles'] === []) {
            $decoded['profiles'] = new \stdClass;
        }

        return $decoded;
    }

    /**
     * Start a run and return (`cipi backup run --detach`): its output goes to
     * backup.log on the server and its result shows in {@see status()}.
     *
     * @throws \RuntimeException
     */
    public function run(?string $profile = null): string
    {
        $this->requireCore();
        $command = 'backup run --detach';
        if ($profile !== null && $profile !== '') {
            $command .= ' --profile=' . escapeshellarg($profile);
        }

        return $this->plain($command, 'cipi backup run');
    }

    /** @throws \RuntimeException */
    public function toggle(string $profile, bool $enabled): string
    {
        $this->requireCore();

        return $this->plain('backup profile ' . ($enabled ? 'enable' : 'disable') . ' ' . escapeshellarg($profile), 'cipi backup profile');
    }

    /**
     * Remove a profile. Its archives stay on every destination (the CLI says
     * how many, and how long the remaining profiles keep them); deleting them
     * is `cipi backup profile remove <name> --purge` on the server.
     *
     * @throws \RuntimeException
     */
    public function remove(string $profile): string
    {
        $this->requireCore();

        return $this->plain('backup profile remove ' . escapeshellarg($profile), 'cipi backup profile remove');
    }

    /**
     * List each S3 destination with its own credentials, or one of them.
     *
     * @return array{ok: bool, output: string}
     *
     * @throws \RuntimeException on an older core
     */
    public function testDestinations(?string $name = null): array
    {
        $this->requireCore();
        $command = 'backup destination test';
        if ($name !== null && $name !== '') {
            $command .= ' ' . escapeshellarg($name);
        }
        $result = $this->cli->run($command);

        return ['ok' => $result['exit_code'] === 0, 'output' => $this->strip($result['output'] ?? '')];
    }

    /** SFTP destinations (Cipi 5.18.0): a server over SSH, with a key Cipi makes. */
    public const MIN_CIPI_SFTP = '5.18.0';

    public const SFTP_HOST_RE = '/^[A-Za-z0-9]([A-Za-z0-9.:-]{0,252}[A-Za-z0-9])?$/';

    public const SFTP_USER_RE = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    public const SFTP_PATH_RE = '#^/?[A-Za-z0-9._/-]{0,255}$#';

    /**
     * What is wrong with the fields of an SFTP destination, by field.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, string>
     */
    public function sftpErrors(array $in, bool $isEdit): array
    {
        $errors = [];
        foreach (['host', 'user'] as $f) {
            if (! $isEdit && (! isset($in[$f]) || $in[$f] === '')) {
                $errors[$f] = "{$f} is required";
            }
        }
        if (isset($in['host']) && (! is_string($in['host']) || ! preg_match(self::SFTP_HOST_RE, $in['host']))) {
            $errors['host'] = 'host must be an IP address or a hostname';
        }
        if (isset($in['user']) && (! is_string($in['user']) || ! preg_match(self::SFTP_USER_RE, $in['user']))) {
            $errors['user'] = 'user: letters, digits, . _ - (64 at most)';
        }
        if (array_key_exists('port', $in) && $in['port'] !== null && (filter_var($in['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false)) {
            $errors['port'] = 'port must be between 1 and 65535';
        }
        if (array_key_exists('path', $in) && $in['path'] !== null && (! is_string($in['path']) || ! preg_match(self::SFTP_PATH_RE, $in['path']) || str_contains($in['path'], '..'))) {
            $errors['path'] = 'path: a folder on the remote — letters, digits, . _ - /, no ..';
        }

        return $errors;
    }

    /**
     * Add or change an SFTP destination: `cipi backup sftp add|edit <name> …`. Cipi makes the key on the server; the answer
     * carries its public half, to install on the remote. No secret is sent.
     *
     * @param  array<string, mixed>  $in  host, port, user, path, new_key
     * @return array<string, mixed>  name, type, host, port, user, path, public_key
     */
    public function saveSftp(string $name, array $in, bool $isEdit): array
    {
        $this->version->require(self::MIN_CIPI_SFTP, 'SFTP backup destinations');
        $command = 'backup sftp ' . ($isEdit ? 'edit' : 'add') . ' ' . escapeshellarg($name);
        foreach (['host', 'port', 'user', 'path'] as $f) {
            if (array_key_exists($f, $in) && $in[$f] !== null) {
                $command .= ' ' . escapeshellarg("--{$f}=" . $in[$f]);
            }
        }
        if (! empty($in['new_key'])) {
            $command .= ' --new-key';
        }
        $command .= ' --json';
        $result = $this->cli->run($command);
        $output = $this->strip($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstError($output, 'cipi backup destination failed'));
        }
        $decoded = CipiAppJitService::decode($output);
        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'sftp') {
            throw new \RuntimeException('Cipi CLI returned no destination (Cipi CLI ≥ ' . self::MIN_CIPI_SFTP . ' required)');
        }

        return $decoded;
    }

    /** The public key an SFTP destination must accept. */
    public function sftpKey(string $name): string
    {
        $this->version->require(self::MIN_CIPI_SFTP, 'SFTP backup destinations');
        $key = $this->plain('backup sftp key ' . escapeshellarg($name) . ' --raw', 'cipi backup sftp key');
        $line = trim((string) strtok($key, "\n"));
        if (! preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp\d+) [A-Za-z0-9+/=]+( \S+)?$#', $line)) {
            throw new \RuntimeException("No key for '{$name}'");
        }

        return $line;
    }

    /**
     * Remove a destination — an SFTP server, and from Cipi 5.19.0 an S3
     * bucket too. Its archives stay where they are.
     */
    public function removeDestination(string $name): string
    {
        if ($this->version->atLeast(self::MIN_CIPI_PANEL)) {
            return $this->plain('backup destination remove ' . escapeshellarg($name), 'cipi backup destination remove');
        }
        $this->version->require(self::MIN_CIPI_SFTP, 'Removing a backup destination from the panel');

        return $this->plain('backup sftp remove ' . escapeshellarg($name), 'cipi backup sftp remove');
    }

    // ── S3 buckets, the default bucket, the key, the retention (Cipi 5.19.0) ──

    /** Backups set up and managed from the panel: S3 buckets, `configure`, the key, prune. */
    public const MIN_CIPI_PANEL = '5.19.0';

    public const BUCKET_RE = '/^[a-z0-9][a-z0-9._-]{1,62}$/D';

    public const REGION_RE = '/^[A-Za-z0-9-]{1,64}$/D';

    public const ENDPOINT_RE = '#^https?://[A-Za-z0-9.:/_-]{1,255}$#D';

    /** An access key or a secret key: printable, no space, no quote, no backslash. */
    public const CRED_RE = '/^[\x21\x23-\x26\x28-\x5b\x5d-\x7e]{1,256}$/D';

    /**
     * What is wrong with the fields of an S3 bucket, by field.
     *
     * @param  array<string, mixed>  $in  bucket, key, secret, region, endpoint
     * @return array<string, string>
     */
    public function s3Errors(array $in, bool $isEdit): array
    {
        $errors = [];
        if (! $isEdit) {
            foreach (['bucket', 'key', 'secret'] as $f) {
                if (! isset($in[$f]) || $in[$f] === '') {
                    $errors[$f] = "{$f} is required";
                }
            }
        }
        $checks = ['bucket' => [self::BUCKET_RE, 'bucket: lowercase letters, digits, . _ - (3 to 63)'],
            'region' => [self::REGION_RE, 'region: letters, digits and dashes'],
            'endpoint' => [self::ENDPOINT_RE, 'endpoint: an http(s) URL'],
            'key' => [self::CRED_RE, 'key: printable characters, no space, no quote'],
            'secret' => [self::CRED_RE, 'secret: printable characters, no space, no quote']];
        foreach ($checks as $f => [$re, $message]) {
            if ($f === 'endpoint' && ($in[$f] ?? null) === 'none') {
                continue; // no endpoint any more: AWS
            }
            if (isset($in[$f]) && $in[$f] !== '' && ! isset($errors[$f]) && (! is_string($in[$f]) || ! preg_match($re, $in[$f]))) {
                $errors[$f] = $message;
            }
        }

        return $errors;
    }

    /**
     * Add or change an S3 bucket: `cipi backup destination add|edit <name> …
     * --secret-stdin --json`. The secret key, when given, goes on the CLI's
     * standard input — never on its command line; the answer never has it.
     *
     * @param  array<string, mixed>  $in  bucket, key, secret, region, endpoint
     * @return array<string, mixed>  name, type, bucket, region, endpoint
     */
    public function saveS3(string $name, array $in, bool $isEdit): array
    {
        $this->version->require(self::MIN_CIPI_PANEL, 'S3 backup destinations from the panel');
        $command = 'backup destination ' . ($isEdit ? 'edit' : 'add') . ' ' . escapeshellarg($name) . $this->s3Flags($in) . ' --json';
        $decoded = CipiAppJitService::decode($this->withSecret($command, $in['secret'] ?? null, 'cipi backup destination'));
        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 's3') {
            throw new \RuntimeException('Cipi CLI returned no destination (Cipi CLI ≥ ' . self::MIN_CIPI_PANEL . ' required)');
        }

        return $decoded;
    }

    /**
     * Set up backups: the default bucket ('s3' in profiles) — `bucket`, `key`,
     * `secret` (kept when not given), `region`, `endpoint` — or `local_only`.
     * The bucket is reached with the keys before anything is saved. The local
     * and temp folders stay the server's.
     *
     * @param  array<string, mixed>  $in
     */
    public function configure(array $in): string
    {
        $this->version->require(self::MIN_CIPI_PANEL, 'Setting up backups from the panel');
        if (! empty($in['local_only'])) {
            return $this->plain('backup configure --local-only', 'cipi backup configure');
        }

        return $this->withSecret('backup configure' . $this->s3Flags($in), $in['secret'] ?? null, 'cipi backup configure');
    }

    /** The encryption key, once — logged and notified on the server. Never kept here. */
    public function encryptionKey(): string
    {
        $this->version->require(self::MIN_CIPI_PANEL, 'The backup key from the panel');
        $key = trim($this->plain('backup key show --raw', 'cipi backup key show'));
        if ($key === '' || str_contains($key, "\n")) {
            throw new \RuntimeException('Cipi CLI returned no key');
        }

        return $key;
    }

    /** A new key for the runs to come; the old one is kept on the server for the old runs. */
    public function rotateKey(): string
    {
        $this->version->require(self::MIN_CIPI_PANEL, 'Rotating the backup key from the panel');

        return $this->plain('backup key rotate', 'cipi backup key rotate');
    }

    /** The command of a prune: the retention applied now (a job). */
    public function pruneCommand(?string $profile, bool $dryRun): string
    {
        $this->version->require(self::MIN_CIPI_PANEL, 'Pruning backups from the panel');
        $command = 'backup prune';
        if ($profile !== null) {
            $command .= ' --profile=' . escapeshellarg($profile);
        }

        return $dryRun ? $command . ' --dry-run' : $command;
    }

    /** @param  array<string, mixed>  $in */
    protected function s3Flags(array $in): string
    {
        $flags = '';
        foreach (['bucket', 'key', 'region', 'endpoint'] as $f) {
            if (isset($in[$f]) && is_string($in[$f]) && $in[$f] !== '') {
                $flags .= ' ' . escapeshellarg("--{$f}=" . $in[$f]);
            }
        }
        if (isset($in['secret']) && is_string($in['secret']) && $in['secret'] !== '') {
            $flags .= ' --secret-stdin';
        }

        return $flags;
    }

    /** Run a command, the secret on its standard input when there is one. */
    protected function withSecret(string $command, mixed $secret, string $label): string
    {
        $result = is_string($secret) && $secret !== ''
            ? $this->cli->runWithInput($command, $secret)
            : $this->cli->run($command);
        $output = $this->strip($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstError($output, "{$label} failed"));
        }

        return $output;
    }

    /**
     * Validate the fields of a profile and turn them into CLI flags.
     *
     * `$isEdit` false: a new profile — what is not given takes the CLI's
     * defaults. True: only the given fields change; the two exclusion lists
     * are always sent when present, empty included (`--exclude-databases=`
     * empties the list).
     *
     * @param  array<string, mixed>  $in
     * @return array{flags: list<string>, errors: array<string, string>}
     */
    public function profileFlags(array $in, bool $isEdit): array
    {
        $flags = [];
        $errors = [];

        if (array_key_exists('scope', $in) && $in['scope'] !== null) {
            if (! in_array($in['scope'], self::SCOPES, true)) {
                $errors['scope'] = 'scope must be one of: ' . implode(', ', self::SCOPES);
            } else {
                $flags[] = '--scope=' . escapeshellarg((string) $in['scope']);
            }
        }

        foreach ([
            'apps' => ['--apps', self::GLOB_RE, false],
            'databases' => ['--databases', self::GLOB_RE, false],
            'exclude_databases' => ['--exclude-databases', self::GLOB_RE, true],
            'exclude_tables' => ['--exclude-tables', self::TABLE_GLOB_RE, true],
            'exclude_paths' => ['--exclude-paths', self::PATH_RE, true],
        ] as $field => [$flag, $re, $mayBeEmpty]) {
            if (! array_key_exists($field, $in) || $in[$field] === null) {
                continue;
            }
            $list = $this->stringList($in[$field]);
            if ($list === null) {
                $errors[$field] = "{$field} must be a list of names or globs";

                continue;
            }
            foreach ($list as $item) {
                if ($field === 'exclude_paths') {
                    if (! $this->version->atLeast(self::MIN_CIPI_EXCLUDE_PATHS)) {
                        $errors[$field] = 'exclude_paths needs Cipi ' . self::MIN_CIPI_EXCLUDE_PATHS . '+ on the server (cipi self-update)';

                        continue 2;
                    }
                    $pattern = preg_replace('/^[a-z0-9*?-]+:/', '', $item);
                    if (! preg_match($re, $item) || in_array($pattern, self::PATH_WHOLE_APP, true)) {
                        $errors[$field] = "Invalid entry '{$item}' in exclude_paths (a path relative to the app, e.g. storage/app/uploads or *.mp4, optionally <apps>: first; never absolute, never the whole app)";

                        continue 2;
                    }

                    continue;
                }
                if (! preg_match($re, $item)) {
                    $errors[$field] = "Invalid entry '{$item}' in {$field} (letters, digits, _ . - and the globs * ? [ ])";

                    continue 2;
                }
            }
            if ($list === []) {
                // An empty selector is the CLI's to judge (scope files with
                // no apps is refused there); an empty exclusion clears it —
                // exclude_paths only on a core that knows it (nothing to clear
                // before 5.13.0).
                if ($field === 'exclude_paths' && ! $this->version->atLeast(self::MIN_CIPI_EXCLUDE_PATHS)) {
                    continue;
                }
                if ($mayBeEmpty && $isEdit) {
                    $flags[] = $flag . '=';
                }

                continue;
            }
            $flags[] = $flag . '=' . escapeshellarg(implode(',', $list));
        }

        $every = isset($in['every']) && $in['every'] !== '' ? (string) $in['every'] : null;
        $cron = isset($in['cron']) && $in['cron'] !== '' ? trim((string) $in['cron']) : null;
        if ($every !== null && $cron !== null) {
            $errors['every'] = 'Give either every (30m, 6h, 1d) or cron, not both';
        } elseif ($every !== null) {
            if (! preg_match(self::EVERY_RE, $every)) {
                $errors['every'] = 'every must look like 30m, 6h or 1d';
            } else {
                $flags[] = '--every=' . escapeshellarg($every);
            }
        } elseif ($cron !== null) {
            if (! preg_match(self::CRON_RE, $cron)) {
                $errors['cron'] = 'cron must be 5 fields of digits and * / , -';
            } else {
                $flags[] = '--cron=' . escapeshellarg($cron);
            }
        }

        if (array_key_exists('destinations', $in) && $in['destinations'] !== null) {
            $list = $this->stringList($in['destinations']);
            if ($list === null || $list === []) {
                $errors['destinations'] = 'destinations must name at least one of: local, s3, or a named destination';
            } else {
                $bad = null;
                foreach ($list as $item) {
                    if ($item !== 'local' && $item !== 's3' && ! preg_match(self::NAME_RE, $item)) {
                        $bad = $item;
                        break;
                    }
                }
                if ($bad !== null) {
                    $errors['destinations'] = "Invalid destination '{$bad}'";
                } else {
                    $flags[] = '--dest=' . escapeshellarg(implode(',', $list));
                }
            }
        }

        // Retention is one object for the CLI: giving one of the three resets
        // the other two to 0, so all three travel together.
        $ret = [];
        foreach (['keep' => '--keep', 'keep_days' => '--keep-days', 'keep_weeks' => '--keep-weeks'] as $field => $flag) {
            if (! array_key_exists($field, $in) || $in[$field] === null || $in[$field] === '') {
                continue;
            }
            if (! is_numeric($in[$field]) || (int) $in[$field] < 0 || (int) $in[$field] > 100000 || (string) (int) $in[$field] !== (string) $in[$field]) {
                $errors[$field] = "{$field} must be a whole number, 0 or more";

                continue;
            }
            $ret[$flag] = (int) $in[$field];
        }
        if ($ret !== []) {
            if (array_sum($ret) === 0) {
                $errors['keep'] = 'At least one of keep, keep_days, keep_weeks must be greater than 0';
            } else {
                foreach (['--keep', '--keep-days', '--keep-weeks'] as $flag) {
                    $flags[] = $flag . '=' . ($ret[$flag] ?? 0);
                }
            }
        }

        if (array_key_exists('encrypt', $in) && $in['encrypt'] !== null) {
            $flags[] = filter_var($in['encrypt'], FILTER_VALIDATE_BOOLEAN) ? '--encrypt' : '--no-encrypt';
        }

        return ['flags' => $flags, 'errors' => $errors];
    }

    /**
     * `cipi backup profile add|edit <name> <flags>`.
     *
     * @param  list<string>  $flags  from {@see profileFlags()}
     *
     * @throws \RuntimeException with the CLI's own message when it refuses
     */
    public function saveProfile(string $name, array $flags, bool $isEdit): string
    {
        $this->requireCore();
        $command = 'backup profile ' . ($isEdit ? 'edit' : 'add') . ' ' . escapeshellarg($name);
        if ($flags !== []) {
            $command .= ' ' . implode(' ', $flags);
        }

        return $this->plain($command, 'cipi backup profile');
    }

    /**
     * A JSON list of non-empty strings, or a comma-separated string.
     *
     * @return list<string>|null
     */
    protected function stringList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (! is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item)) {
                return null;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(string $command, string $label): array
    {
        $result = $this->cli->run($command);
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->refusedBySudo($output, $command) ?? $this->firstError($output, "{$label} failed"));
        }
        $decoded = $this->decode($output);
        if ($decoded === null) {
            $said = $this->strip($output);
            $said = $said === '' ? 'nothing' : '"' . mb_substr((string) preg_replace('/\s+/', ' ', $said), 0, 200) . '"';

            throw new \RuntimeException("{$label} returned no JSON (Cipi CLI ≥ " . self::MIN_CIPI . " required) — it printed {$said}");
        }

        return $decoded;
    }

    /**
     * The JSON object in the CLI's output. stderr is read together with
     * stdout, so a line sudo or the shell prints around it ("sudo: unable to
     * resolve host …") must not turn a good answer into "no JSON": the object
     * is then taken from its first `{` to its last `}`, as the disk and
     * cipi.yml services do.
     *
     * @return array<string, mixed>|null
     */
    protected function decode(string $output): ?array
    {
        $output = trim($output);
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            $start = strpos($output, '{');
            $end = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * sudo refusing the command, said as what it is. The rule the panel runs
     * `cipi` through (/etc/sudoers.d/cipi-api) lists every command by name;
     * the backup ones are written by Cipi 5.9.0's migration. A server whose
     * rule is older answers with sudo's own sentence, which says nothing of
     * that.
     */
    protected function refusedBySudo(string $output, string $command): ?string
    {
        $plain = $this->strip($output);
        // sudo's and sudo-rs's refusals. Not every line sudo prints: "unable
        // to resolve host" is a warning, and the command still ran.
        if (! preg_match('/^.*(a password is required|a terminal is required|is not allowed to (?:run|execute)|not in the sudoers|may not run sudo|I\'m afraid I can\'t do that).*$/mi', $plain, $m)) {
            return null;
        }
        $said = mb_substr(trim($m[0]), 0, 200);
        $verb = implode(' ', array_slice(explode(' ', $command), 0, 2));

        return "sudo refused `cipi {$verb}` for the panel ({$said}). The rule in /etc/sudoers.d/cipi-api does not list it: "
            . 'the migration of Cipi ' . self::MIN_CIPI . ' writes it (cipi self-update). Check the file on the server: '
            . "visudo -cf /etc/sudoers.d/cipi-api && grep -c 'cipi backup' /etc/sudoers.d/cipi-api";
    }

    protected function plain(string $command, string $label): string
    {
        $result = $this->cli->run($command);
        $output = $this->strip($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstError($output, "{$label} failed"));
        }

        return $output;
    }

    protected function strip(string $text): string
    {
        return trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $text));
    }

    /** The CLI's `[ERROR] …` lines, or the whole output when it has none. */
    protected function firstError(string $output, string $fallback): string
    {
        $plain = $this->strip($output);
        if (preg_match_all('/^\s*(?:\[ERROR\]|✗|Error:)\s*(.+)$/mu', $plain, $m) && $m[1] !== []) {
            return implode(' ', array_map('trim', $m[1]));
        }

        return $plain !== '' ? $plain : $fallback;
    }
}
