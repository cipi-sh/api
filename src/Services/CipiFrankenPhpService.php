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
}
