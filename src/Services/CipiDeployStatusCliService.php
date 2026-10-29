<?php

namespace CipiApi\Services;

/**
 * Is a deploy running for an app right now? `sudo cipi deploy <app> --status
 * --json` (Cipi CLI ≥ 5.8.4) reads the start/end banners of the app's deploy
 * log and the live processes, so it sees a deploy whoever started it — a Git
 * push through the webhook, `cipi deploy` on the server, the panel, a
 * rollback — and how the last one ended.
 *
 * The version gate is not optional: on an older core `--status` is an unknown
 * flag and `cipi deploy <app>` would start a deploy instead.
 */
class CipiDeployStatusCliService
{
    public const MIN_CIPI = '5.8.4';

    public const MAX_TAIL = 500;

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /**
     * @param  int  $tail  Last N lines of the deploy log to include (0 = none, max 500)
     * @return array{app: string, state: string, running: bool, phase: ?string, queued: bool, locked: bool,
     *               current: ?array, last: ?array, log: string, tail?: list<string>}
     *
     * @throws \RuntimeException on an older core or a CLI failure
     */
    public function show(string $app, int $tail = 0): array
    {
        $this->version->require(self::MIN_CIPI, 'Deploy status');

        $tail = max(0, min(self::MAX_TAIL, $tail));
        $command = 'deploy ' . escapeshellarg($app) . ' --status --json';
        if ($tail > 0) {
            $command .= ' --tail=' . $tail;
        }

        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi deploy --status failed (Cipi CLI ≥ 5.8.4 required)');
        }

        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded) || ! isset($decoded['state'])) {
            throw new \RuntimeException('cipi deploy --status returned no JSON (Cipi CLI ≥ 5.8.4 required)');
        }

        return $decoded;
    }
}
