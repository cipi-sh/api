<?php

namespace CipiApi\Services;

/**
 * Structured deploy.php options via `sudo cipi app deploy-config` (Cipi ≥ 5.0.3).
 * Not a raw PHP file editor — knobs live in apps.json and regenerate the template.
 */
class CipiAppDeployConfigService
{
    /** The Cipi that knows the two clocks of a deploy, and gives a Node app its deploy options. */
    public const MIN_CIPI_TIMEOUTS = '5.18.0';

    /**
     * Seconds, as the CLI takes them (deploy_timeout_bounds): the frontend
     * build — 1800 unless the app says otherwise — and every other command
     * of the deploy, 300.
     *
     * @var array<string, array{0: int, 1: int, 2: string}>
     */
    public const TIMEOUTS = [
        'build_timeout' => [60, 3600, 'build-timeout'],
        'step_timeout' => [60, 1800, 'step-timeout'],
    ];

    /** What a Node app's recipe takes: it has no artisan hooks, and its build is `build` on the app. */
    public const NODE_OPTIONS = ['keep_releases', 'predeploy_snapshot', 'build_timeout', 'step_timeout'];

    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
        protected ?CipiVersionService $version = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(string $app): array
    {
        $this->assertHasRecipe($app);

        $result = $this->cli->run('app deploy-config ' . escapeshellarg($app) . ' --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app deploy-config failed');
        }

        return $this->decodeJsonObject($result['output'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function update(string $app, array $options): array
    {
        $this->assertHasRecipe($app);

        if ($this->validator->isNodeApp($app)) {
            $notForNode = array_diff(array_keys(array_filter($options, fn ($v) => $v !== null)), self::NODE_OPTIONS);
            if ($notForNode !== []) {
                throw new \InvalidArgumentException("'{$app}' is a Node app: its recipe has no artisan hooks. It takes " . implode(', ', self::NODE_OPTIONS) . ' — not ' . implode(', ', $notForNode) . '.');
            }
        }

        $flags = $this->buildFlags($options);
        if ($flags === []) {
            throw new \InvalidArgumentException('Nothing to change');
        }
        // An older CLI would take the flag for nothing and answer as if it had been applied.
        foreach (array_keys(self::TIMEOUTS) as $key) {
            if (isset($options[$key]) && ! $this->knowsTimeouts()) {
                throw new \RuntimeException("{$key} needs Cipi " . self::MIN_CIPI_TIMEOUTS . '+ on the server (cipi self-update)');
            }
        }

        $cmd = 'app deploy-config ' . escapeshellarg($app) . ' ' . implode(' ', $flags) . ' --json';
        $result = $this->cli->run($cmd);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app deploy-config update failed');
        }

        return $this->decodeJsonObject($result['output'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function buildFlags(array $options): array
    {
        $flags = [];

        if (array_key_exists('keep_releases', $options) && $options['keep_releases'] !== null) {
            $kr = (int) $options['keep_releases'];
            if ($kr < 1 || $kr > 20) {
                throw new \InvalidArgumentException('keep_releases must be between 1 and 20');
            }
            $flags[] = '--keep-releases=' . escapeshellarg((string) $kr);
        }

        foreach (self::TIMEOUTS as $key => [$min, $max, $flag]) {
            if (! array_key_exists($key, $options) || $options[$key] === null) {
                continue;
            }
            $seconds = $options[$key];
            if ((! is_int($seconds) && ! (is_string($seconds) && ctype_digit($seconds))) || (int) $seconds < $min || (int) $seconds > $max) {
                throw new \InvalidArgumentException("{$key} must be a number of seconds between {$min} and {$max}");
            }
            $flags[] = '--' . $flag . '=' . escapeshellarg((string) (int) $seconds);
        }

        foreach ([
            'migrate' => 'migrate',
            'optimize' => 'optimize',
            'storage_link' => 'storage-link',
            'queue_restart' => 'queue-restart',
            'horizon_terminate' => 'horizon-terminate',
            'predeploy_snapshot' => 'predeploy-snapshot',
        ] as $key => $flag) {
            if (! array_key_exists($key, $options) || $options[$key] === null) {
                continue;
            }
            $flags[] = $options[$key] ? '--' . $flag : '--no-' . $flag;
        }

        if (array_key_exists('node_build', $options)) {
            $nb = $options['node_build'];
            if ($nb === null || $nb === '') {
                $flags[] = '--no-node-build';
            } else {
                if (! is_string($nb)) {
                    throw new \InvalidArgumentException('node_build must be a string or null');
                }
                $flags[] = '--node-build=' . escapeshellarg($nb);
            }
        }

        if (array_key_exists('extra_artisan', $options)) {
            $extra = $options['extra_artisan'];
            if ($extra === null || $extra === []) {
                $flags[] = '--clear-extra-artisan';
            } elseif (is_array($extra)) {
                $clean = [];
                foreach ($extra as $cmd) {
                    if (! is_string($cmd) || ! preg_match('/^[a-zA-Z0-9:_-]+$/', $cmd)) {
                        throw new \InvalidArgumentException("Invalid extra_artisan command: {$cmd}");
                    }
                    if (strtolower($cmd) === 'tinker') {
                        throw new \InvalidArgumentException('tinker is not allowed in extra_artisan');
                    }
                    $clean[] = $cmd;
                }
                $flags[] = '--extra-artisan=' . escapeshellarg(implode(',', $clean));
            } else {
                throw new \InvalidArgumentException('extra_artisan must be an array of command names');
            }
        }

        return $flags;
    }

    /**
     * Laravel and Octane apps have a release recipe, and so has a Node app —
     * which Cipi keeps as a custom app with a runtime, and whose deploy
     * options the CLI takes from 5.18.0. A classic custom app has none.
     */
    protected function assertHasRecipe(string $app): void
    {
        if (! $this->validator->appExists($app)) {
            throw new \RuntimeException("App '{$app}' not found");
        }
        if ($this->validator->isNodeApp($app)) {
            if (! $this->knowsTimeouts()) {
                throw new \RuntimeException("The deploy options of a Node app need Cipi " . self::MIN_CIPI_TIMEOUTS . '+ on the server (cipi self-update)');
            }

            return;
        }
        if ($this->validator->isCustomApp($app)) {
            throw new \RuntimeException("App '{$app}' is a custom app and has no deploy.php recipe");
        }
    }

    protected function knowsTimeouts(): bool
    {
        return $this->version === null || $this->version->atLeast(self::MIN_CIPI_TIMEOUTS);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonObject(string $output): array
    {
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output;
        if (! preg_match('/\{.*\}/s', $plain, $m)) {
            throw new \RuntimeException('Could not parse deploy-config JSON from cipi output');
        }

        $decoded = json_decode($m[0], true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid deploy-config JSON from cipi');
        }

        return $decoded;
    }
}
