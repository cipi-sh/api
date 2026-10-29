<?php

namespace CipiApi\Services;

/**
 * The Cipi version installed on this server, for routes that must not run a
 * CLI flag the installed core does not know. `cipi deploy <app> --status`
 * is the case in point: on a core older than 5.8.4 the flag is unknown,
 * `deploy_command` falls through to a real deploy, and a status poll would
 * publish a release. So the flag is sent only once the version is known to
 * carry it; an unknown version counts as too old.
 */
class CipiVersionService
{
    public const VERSION_FILE = '/etc/cipi/version';

    protected ?string $installed = null;

    protected bool $resolved = false;

    /**
     * Installed version ("5.8.4"), null when it cannot be read.
     * `cipi version` prints the same file (`Cipi v5.8.4`); it is the fallback
     * when the file is not readable from the API's user.
     */
    public function installed(): ?string
    {
        if ($this->resolved) {
            return $this->installed;
        }
        $this->resolved = true;

        $file = @file_get_contents(self::VERSION_FILE);
        if ($file !== false && preg_match('/\d+\.\d+\.\d+/', $file, $m)) {
            return $this->installed = $m[0];
        }

        $output = trim((string) shell_exec('/usr/local/bin/cipi version 2>/dev/null'));
        if ($output !== '' && preg_match('/v?(\d+\.\d+\.\d+)/', $output, $m)) {
            return $this->installed = $m[1];
        }

        return $this->installed = null;
    }

    /** True only when the installed version is known and not older than $minimum. */
    public function atLeast(string $minimum): bool
    {
        $installed = $this->installed();

        return $installed !== null && version_compare($installed, $minimum, '>=');
    }

    /**
     * @throws \RuntimeException when the installed core is older than $minimum or unknown
     */
    public function require(string $minimum, string $feature): void
    {
        if ($this->atLeast($minimum)) {
            return;
        }
        $installed = $this->installed();

        throw new \RuntimeException(
            "{$feature} needs Cipi {$minimum}+ — this server runs "
            . ($installed !== null ? "Cipi {$installed}" : 'an unknown Cipi version')
            . '. Run: cipi self-update'
        );
    }
}
