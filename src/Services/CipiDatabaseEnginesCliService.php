<?php

namespace CipiApi\Services;

use CipiApi\Exceptions\MysqlDatabaseListingUnavailableException;

/**
 * Lists installed DB engines via `sudo cipi db engines` (Cipi 4.8+).
 */
class CipiDatabaseEnginesCliService
{
    /** `cipi db engines --json`; an older core's sudoers refuses the flag. */
    public const MIN_CIPI_JSON = '5.13.0';

    public function __construct(
        protected CipiCliService $cli,
        protected CipiOutputParser $parser,
        protected ?CipiVersionService $version = null,
    ) {}

    /**
     * `cipi db engines --json` (Cipi 5.13.0), or the table of an older core.
     * Each engine says which server runs on it: `server` is mariadb or mysql
     * for the engine "mariadb" (cipi db switch), and `label` its name. The
     * engine id stays "mariadb" either way.
     *
     * @return array{default: string|null, engines: list<array{engine: string, status: string, port: int|null, default: bool, server: string, label: string}>}
     */
    public function list(): array
    {
        $json = $this->version !== null && $this->version->atLeast(self::MIN_CIPI_JSON)
            ? $this->cli->run('db engines --json') : ['output' => '', 'exit_code' => 1];
        $decoded = json_decode(trim((string) ($json['output'] ?? '')), true);
        if ($json['exit_code'] === 0 && is_array($decoded) && is_array($decoded['engines'] ?? null)) {
            $engines = [];
            foreach ($decoded['engines'] as $e) {
                if (! is_array($e) || ! isset($e['engine'])) {
                    continue;
                }
                $engine = strtolower((string) $e['engine']);
                $server = strtolower((string) ($e['server'] ?? $engine));
                $engines[] = [
                    'engine' => $engine,
                    'status' => (string) ($e['status'] ?? 'not_installed'),
                    'port' => isset($e['port']) && is_numeric($e['port']) ? (int) $e['port'] : null,
                    'default' => (bool) ($e['default'] ?? false),
                    'server' => $server,
                    'label' => (string) ($e['label'] ?? $this->parser->engineLabel($server)),
                ];
            }

            return ['default' => isset($decoded['default']) ? (string) $decoded['default'] : null, 'engines' => $engines];
        }

        $result = $this->cli->run('db engines');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            $msg = $detail !== '' ? $detail : ('cipi db engines exited with code ' . $result['exit_code']);

            throw new MysqlDatabaseListingUnavailableException($msg);
        }

        $parsed = $this->parser->parse('db-engines', $result['output'], true);

        return [
            'default' => is_array($parsed) ? ($parsed['default'] ?? null) : null,
            'engines' => is_array($parsed) && is_array($parsed['engines'] ?? null) ? $parsed['engines'] : [],
        ];
    }
}
