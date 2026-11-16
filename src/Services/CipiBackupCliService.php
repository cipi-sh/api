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

    public const SCOPES = ['all', 'files', 'db'];

    /** Same shapes the CLI and cipi.yml accept. */
    protected const NAME_RE = '/^[a-z][a-z0-9-]{1,31}$/';

    protected const GLOB_RE = '/^[a-zA-Z0-9_.*?\[\]-]{1,64}$/';

    protected const TABLE_GLOB_RE = '/^[a-zA-Z0-9_.*?\[\]-]{1,128}$/';

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

        return $this->json('backup status --json', 'cipi backup status');
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
                if (! preg_match($re, $item)) {
                    $errors[$field] = "Invalid entry '{$item}' in {$field} (letters, digits, _ . - and the globs * ? [ ])";

                    continue 2;
                }
            }
            if ($list === []) {
                // An empty selector is the CLI's to judge (scope files with
                // no apps is refused there); an empty exclusion clears it.
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
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstError($result['output'] ?? '', "{$label} failed"));
        }
        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$label} returned no JSON (Cipi CLI ≥ " . self::MIN_CIPI . ' required)');
        }

        return $decoded;
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
