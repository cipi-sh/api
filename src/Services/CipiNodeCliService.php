<?php

namespace CipiApi\Services;

/**
 * Node frontend apps and Node runtimes (Cipi CLI ≥ 5.4.0; API sudoers ≥ 5.4.1).
 *
 * App status is read from apps.json (`runtime`, `node_mode`, `node_version`, …);
 * runtimes come from `sudo cipi node list` and restarts go through
 * `sudo cipi node restart` (blue/green, no downtime).
 */
class CipiNodeCliService
{
    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
    ) {}

    /**
     * Installed Node runtimes (`cipi node list`).
     *
     * @return list<array{major: string, version: ?string, default: bool, apps: list<string>}>
     */
    public function runtimes(): array
    {
        $result = $this->cli->run('node list');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi node list failed (Cipi CLI ≥ 5.4.1 required)');
        }

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $result['output'] ?? '');
        $runtimes = [];
        foreach (explode("\n", $plain) as $line) {
            if (! preg_match('/^\s{2}(\d{2}|system)\s+(\S+)(.*)$/', $line, $m)) {
                continue;
            }
            $rest = preg_split('/\s+/', trim($m[3]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $default = in_array('default', $rest, true);
            $apps = [];
            foreach ($rest as $token) {
                if ($token === 'default' || $token === '-') {
                    continue;
                }
                if (preg_match('/^[a-z][a-z0-9]*(,[a-z][a-z0-9]*)*$/', $token)) {
                    $apps = explode(',', $token);
                }
            }
            $runtimes[] = [
                'major' => $m[1],
                'version' => $m[2] !== '?' ? $m[2] : null,
                'default' => $default,
                'apps' => $apps,
            ];
        }

        return $runtimes;
    }

    /**
     * Node status for an app, from apps.json.
     *
     * @return array{app: string, node: bool, mode: ?string, framework: ?string, version: ?string, build: ?string, start: ?string, health_path: ?string, output: ?string}
     */
    public function status(string $app): array
    {
        $apps = $this->validator->getApps();
        $row = $apps[$app] ?? [];
        $isNode = ($row['runtime'] ?? '') === 'node';

        $str = static function (string $key) use ($row): ?string {
            $value = $row[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        return [
            'app' => $app,
            'node' => $isNode,
            'mode' => $isNode ? $str('node_mode') : null,
            'framework' => $isNode ? $str('node_framework') : null,
            'version' => $str('node_version'),
            'build' => $str('node_build'),
            'start' => $isNode ? $str('node_start') : null,
            'health_path' => $isNode ? $str('node_health') : null,
            'output' => $isNode ? $str('node_output') : null,
        ];
    }
}
