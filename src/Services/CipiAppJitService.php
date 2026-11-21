<?php

namespace CipiApi\Services;

/**
 * PHP JIT per app (`cipi app jit`, Cipi CLI ≥ 5.10.0).
 *
 * The CLI checks, before it writes anything, that the PHP which runs the app
 * starts with JIT (its CLI for PHP-FPM, the FrankenPHP binary for Octane),
 * and after the restart that the app's runtime is up — otherwise it puts the
 * previous setting back. Turning JIT on or off restarts the PHP-FPM of the
 * app's PHP version (every app on it) or the app's Octane, so it is a job.
 */
class CipiAppJitService
{
    public const MIN_CIPI = '5.10.0';

    public const MODES = ['tracing', 'function'];

    /** Validation rules shared by REST and MCP (the CLI's bounds: 16M–1G). */
    public const RULES = [
        'enabled' => 'required|boolean',
        'mode' => 'sometimes|string|in:tracing,function',
        'buffer' => ['sometimes', 'string', 'max:6', 'regex:/^[0-9]{1,4}[MmGg]$/'],
    ];

    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
        protected CipiVersionService $version,
    ) {}

    /**
     * `cipi app jit <app> --json`: runtime, the PHP that answers (for Octane
     * the one inside its FrankenPHP build), enabled, mode, buffer, supported.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on an older core or a CLI failure
     */
    public function show(string $app): array
    {
        $this->version->require(self::MIN_CIPI, 'JIT per app');

        $result = $this->cli->run('app jit ' . escapeshellarg($app) . ' --json');
        $output = (string) ($result['output'] ?? '');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->firstLine($output, 'cipi app jit failed'));
        }
        $decoded = self::decode($output);
        if ($decoded === null || ! array_key_exists('enabled', $decoded)) {
            throw new \RuntimeException('cipi app jit returned no JIT status (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    /**
     * Error for validated input that cannot be applied to this app, or null.
     */
    public function inputError(string $app, array $input): ?string
    {
        if ($this->validator->isNodeApp($app)) {
            return 'A Node app is not served by PHP: JIT does not apply';
        }
        if (! $input['enabled'] && (isset($input['mode']) || isset($input['buffer']))) {
            return 'mode and buffer apply when enabled is true';
        }
        if (isset($input['buffer'])) {
            $mb = self::megabytes($input['buffer']);
            if ($mb < 16 || $mb > 1024) {
                return 'buffer must be between 16M and 1G';
            }
        }
        try {
            $this->version->require(self::MIN_CIPI, 'JIT per app');
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** The `cipi app jit` command for validated input. */
    public function command(string $app, array $input): string
    {
        if (! $input['enabled']) {
            return 'app jit ' . escapeshellarg($app) . ' off';
        }
        $parts = ['app jit', escapeshellarg($app), 'on'];
        if (isset($input['mode'])) {
            $parts[] = '--mode=' . escapeshellarg($input['mode']);
        }
        if (isset($input['buffer'])) {
            $parts[] = '--buffer=' . escapeshellarg(strtoupper($input['buffer']));
        }

        return implode(' ', $parts);
    }

    public static function megabytes(string $size): int
    {
        $n = (int) $size;

        return in_array(strtoupper(substr($size, -1)), ['G'], true) ? $n * 1024 : $n;
    }

    /**
     * The JSON object in the CLI's output, from its first `{` to its last `}`
     * when sudo or the shell printed a line around it.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $output): ?array
    {
        $output = trim($output);
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            $start = strpos($output, '{');
            $end = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    protected function firstLine(string $output, string $fallback): string
    {
        $plain = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', $output));
        if (preg_match('/^\[ERROR\]\s*(.+)$/m', $plain, $m)) {
            return trim($m[1]);
        }

        return $plain !== '' ? mb_substr(strtok($plain, "\n"), 0, 300) : $fallback;
    }
}
