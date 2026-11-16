<?php

namespace CipiApi\Services;

/**
 * Past deploys of an app, and every app's last one (Cipi CLI ≥ 5.9.0).
 *
 * The deploy log is rotated every night: reading `deploy.log` alone — what a
 * client did through the app-logs endpoint — keeps the duration of a deploy
 * until the first rotation and loses it afterwards. A deploy started by a Git
 * push, which nobody watches while it runs, was the one that "had no time".
 * `cipi deploy --history <app>` reads the rotated files too.
 *
 * Both commands are sent with the flag first: a core that does not know it
 * answers "App '--history' not found" to that form instead of deploying.
 * The version gate comes on top of that.
 */
class CipiDeployHistoryCliService
{
    public const MIN_CIPI = '5.9.0';

    public const MAX_LIMIT = 500;

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /**
     * @return array{app: string, count: int, runs: list<array<string, mixed>>, log?: string}
     *
     * @throws \RuntimeException on an older core or a CLI failure
     */
    public function history(string $app, int $limit = 30): array
    {
        $this->version->require(self::MIN_CIPI, 'Deploy history');

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $decoded = $this->json('deploy --history ' . escapeshellarg($app) . ' --json --limit=' . $limit, 'cipi deploy --history');
        if (! isset($decoded['runs']) || ! is_array($decoded['runs'])) {
            throw new \RuntimeException('cipi deploy --history returned no runs (Cipi CLI ≥ 5.9.0 required)');
        }

        return $decoded;
    }

    /**
     * Last finished run of every app: app name => run, or null for an app
     * that was never deployed.
     *
     * @return array<string, array<string, mixed>|null>
     *
     * @throws \RuntimeException on an older core or a CLI failure
     */
    public function last(): array
    {
        $this->version->require(self::MIN_CIPI, 'Last deploys');

        $decoded = $this->json('deploy --last --json', 'cipi deploy --last');
        $apps = $decoded['apps'] ?? null;
        if (! is_array($apps)) {
            throw new \RuntimeException('cipi deploy --last returned no apps (Cipi CLI ≥ 5.9.0 required)');
        }

        return $apps;
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(string $command, string $label): array
    {
        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');

            throw new \RuntimeException($detail !== '' ? $detail : "{$label} failed");
        }
        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$label} returned no JSON");
        }

        return $decoded;
    }
}
