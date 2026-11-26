<?php

namespace CipiApi\Services;

/**
 * FrankenPHP builds of Octane apps (`cipi frankenphp`, Cipi CLI ≥ 5.10.0).
 *
 * An Octane app answers on the PHP built into its FrankenPHP binary, which
 * apt never patches. Cipi pins a build per app and links it into every
 * release; `status` says which build each app runs, the PHP inside it and
 * whether that is patched, and `upgrade` moves an app (or all of them) to the
 * newest build on its own PHP minor and FrankenPHP major — restarting Octane,
 * checking that it answers, and going back to the previous build when it
 * does not. Picking a build by hand (`cipi frankenphp use`, whose --force
 * skips the PHP-minor check) and importing one stay on the server's shell.
 */
class CipiFrankenPhpService
{
    public const MIN_CIPI = '5.10.0';

    /** `cipi frankenphp php` and `restart` (the PHP of an Octane app by its number). */
    public const PHP_MIN_CIPI = '5.17.0';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /**
     * @return array{builds: list<array<string, mixed>>, apps: list<array<string, mixed>>, autoupdate: bool}
     *
     * @throws \RuntimeException on an older core or a CLI failure
     */
    public function status(): array
    {
        $this->version->require(self::MIN_CIPI, 'FrankenPHP builds');

        $result = $this->cli->run('frankenphp status --json');
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));

            throw new \RuntimeException($plain !== '' ? mb_substr($plain, 0, 300) : 'cipi frankenphp status failed');
        }
        $decoded = CipiAppJitService::decode($output);
        if ($decoded === null || ! isset($decoded['apps'], $decoded['builds']) || ! is_array($decoded['apps'])) {
            throw new \RuntimeException('cipi frankenphp status returned no status (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    /**
     * `cipi frankenphp upgrade <app>` or `--all`. Never another PHP minor or
     * FrankenPHP major: the CLI announces those and leaves the app where it is.
     *
     * @throws \RuntimeException on an older core
     */
    public function upgradeCommand(?string $app): string
    {
        $this->version->require(self::MIN_CIPI, 'FrankenPHP upgrade');

        return $app === null
            ? 'frankenphp upgrade --all --yes'
            : 'frankenphp upgrade ' . escapeshellarg($app) . ' --yes';
    }

    /**
     * The PHP of one Octane app (`cipi frankenphp php <app> --json`, Cipi 5.17.0).
     *
     * An Octane app runs two PHP: the one built into its FrankenPHP binary
     * answers the requests (`octane_php`), the server's php<X.Y> runs Composer,
     * artisan, cron and the queue workers (`php`). `state` is `mismatch` when
     * their minors differ, and `fix` then says which command puts them on one.
     * `available_php` are the minors a build on this server exists for; another
     * minor is fetched from FrankenPHP's releases when asked for.
     *
     * @return array{app: string, php: string, octane_php: ?string, build: ?string, frankenphp: ?string,
     *               state: string, restart_pending: bool, available_php: list<string>, fix: ?array{command: string, why: string}}
     *
     * @throws \RuntimeException on an older core or a CLI failure
     */
    public function appPhp(string $app): array
    {
        $this->version->require(self::PHP_MIN_CIPI, 'The PHP of an Octane app');

        $result = $this->cli->run('frankenphp php ' . escapeshellarg($app) . ' --json');
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine($output, 'cipi frankenphp php failed'));
        }
        $decoded = CipiAppJitService::decode($output);
        if ($decoded === null || ! array_key_exists('state', $decoded)) {
            throw new \RuntimeException('cipi frankenphp php returned no status (Cipi CLI ≥ ' . self::PHP_MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    /**
     * `cipi frankenphp php <app> X.Y --yes`: Octane first — the build with PHP
     * X.Y, restarted and checked, the previous build back when it does not
     * answer and nothing else changed — then Composer, artisan, cron and the
     * workers, the way `cipi app edit --php` moves them.
     *
     * @throws \RuntimeException on an older core
     */
    public function phpCommand(string $app, string $php): string
    {
        $this->version->require(self::PHP_MIN_CIPI, 'The PHP of an Octane app');

        return 'frankenphp php ' . escapeshellarg($app) . ' ' . escapeshellarg($php) . ' --yes';
    }

    /**
     * `cipi frankenphp restart <app>`: Octane restarted on its pinned build, and checked.
     *
     * @throws \RuntimeException on an older core
     */
    public function restartCommand(string $app): string
    {
        $this->version->require(self::PHP_MIN_CIPI, 'Restarting Octane');

        return 'frankenphp restart ' . escapeshellarg($app);
    }

    protected function firstLine(string $output, string $fallback): string
    {
        $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));
        if (preg_match('/sudo: .*(not allowed|a password is required|a terminal is required)/i', $plain)) {
            return 'This server cannot switch the PHP of an Octane app from the API yet: Cipi ' . self::PHP_MIN_CIPI . '+ is required (cipi self-update, then cipi api update).';
        }
        if (preg_match('/^\[ERROR\]\s*(.+)$/m', $plain, $m)) {
            return mb_substr(trim($m[1]), 0, 400);
        }

        return $plain !== '' ? mb_substr((string) strtok($plain, "\n"), 0, 300) : $fallback;
    }
}
