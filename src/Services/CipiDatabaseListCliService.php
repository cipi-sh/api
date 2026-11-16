<?php

namespace CipiApi\Services;

use CipiApi\Exceptions\MysqlDatabaseListingUnavailableException;

/**
 * Lists databases by running `sudo cipi db list` on the host — same path as the Cipi server CLI
 * (vault, MariaDB credentials, and output format are handled by Cipi, not duplicated in PHP).
 *
 * On Cipi 5.9.0+ the list is asked as JSON (`--json`), and each database also
 * says which app it belongs to: `app` and `link` — `linked` (cipi db link),
 * `own` (created with a Laravel app) or `namespace` (`<app>_…`). On an older
 * core the table is parsed as before and those two keys are absent.
 */
class CipiDatabaseListCliService
{
    public const JSON_MIN_CIPI = '5.9.0';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiOutputParser $parser,
        protected CipiVersionService $version,
    ) {}

    /**
     * @return list<array{name: string, size?: string, size_mb?: float, engine?: string, user?: ?string, app?: ?string, link?: ?string}>
     */
    public function list(?string $engine = null): array
    {
        $json = $this->version->atLeast(self::JSON_MIN_CIPI);
        $command = 'db list';
        if ($engine !== null && $engine !== '') {
            $command .= ' --engine=' . escapeshellarg($engine);
        }
        if ($json) {
            $command .= ' --json';
        }

        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            $msg = $detail !== '' ? $detail : ('cipi db list exited with code ' . $result['exit_code']);

            throw new MysqlDatabaseListingUnavailableException($msg);
        }

        if ($json) {
            $decoded = json_decode(trim($result['output'] ?? ''), true);
            if (is_array($decoded) && isset($decoded['databases']) && is_array($decoded['databases'])) {
                return array_values($decoded['databases']);
            }

            throw new MysqlDatabaseListingUnavailableException('cipi db list --json returned no JSON');
        }

        $parsed = $this->parser->parse('db-list', $result['output'], true);
        $databases = is_array($parsed) ? ($parsed['databases'] ?? []) : [];

        return is_array($databases) ? $databases : [];
    }
}
