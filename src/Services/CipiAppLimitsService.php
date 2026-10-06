<?php

namespace CipiApi\Services;

/**
 * Per-app resource limits (`cipi app limits`) and the soft disk limit (Cipi CLI ≥ 5.5.0).
 *
 * PHP-FPM / Octane / worker limits are read from apps.json (`limits`). The disk limit
 * lives next to them as `disk_limit_gb`; when the apps.json projection the API reads
 * does not carry that key, it comes from `sudo cipi app limits <app>` (no flags = read-only).
 */
class CipiAppLimitsService
{
    /** Values the CLI applies when a limit is unset. */
    public const DEFAULTS = [
        'fpm_max_children' => 5,
        'memory_limit' => '256M',
        'octane_workers' => 2,
        'worker_procs' => 1,
    ];

    /** Request key → `cipi app limits` flag. */
    public const FLAGS = [
        'fpm_max_children' => 'fpm-max-children',
        'memory_limit' => 'memory-limit',
        'octane_workers' => 'octane-workers',
        'worker_procs' => 'worker-procs',
    ];

    /** Validation rules shared by REST and MCP (CLI ranges; at most two decimals for the disk). */
    public const RULES = [
        'fpm_max_children' => 'sometimes|integer|min:1|max:50',
        'memory_limit' => ['sometimes', 'string', 'max:8', 'regex:/^[0-9]+[MmGg]?$/'],
        'octane_workers' => 'sometimes|integer|min:1|max:16',
        'worker_procs' => 'sometimes|integer|min:1|max:20',
        'disk_limit_gb' => 'sometimes|nullable|numeric|decimal:0,2|gt:0|max:999999.99',
    ];

    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
    ) {}

    /**
     * @return array{app: string, limits: array<string, int|string|null>, defaults: array<string, int|string>, disk_limit_gb: int|float|null}
     */
    public function show(string $app): array
    {
        $row = $this->validator->getApps()[$app] ?? [];
        $stored = is_array($row['limits'] ?? null) ? $row['limits'] : [];

        $limits = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $limits[$key] = $stored[$key] ?? null;
        }

        $disk = array_key_exists('disk_limit_gb', $row)
            ? $row['disk_limit_gb']
            : $this->diskLimitFromCli($app);

        return [
            'app' => $app,
            'limits' => $limits,
            'defaults' => self::DEFAULTS,
            'disk_limit_gb' => is_numeric($disk) ? $disk + 0 : null,
        ];
    }

    /**
     * Error for validated input that cannot be applied to this app, or null.
     */
    public function inputError(string $app, array $input): ?string
    {
        if ($input === []) {
            return 'Nothing to update: set at least one limit';
        }
        if ($this->validator->isNodeApp($app) && array_diff(array_keys($input), ['disk_limit_gb']) !== []) {
            return 'Only disk_limit_gb applies to Node apps';
        }

        return null;
    }

    /**
     * `cipi app limits` command for validated input. `disk_limit_gb: null` removes the disk limit.
     */
    public function command(string $app, array $input): string
    {
        $parts = ['app limits', escapeshellarg($app)];
        foreach (self::FLAGS as $key => $flag) {
            if (array_key_exists($key, $input)) {
                $parts[] = "--{$flag}=" . escapeshellarg((string) $input[$key]);
            }
        }
        if (array_key_exists('disk_limit_gb', $input)) {
            $disk = $input['disk_limit_gb'] === null ? 'none' : self::formatGb($input['disk_limit_gb']);
            $parts[] = '--disk=' . escapeshellarg($disk);
        }

        return implode(' ', $parts);
    }

    /**
     * 2.50 → "2.5", 10 → "10" (the CLI accepts at most two decimals).
     */
    public static function formatGb(int|float|string $gb): string
    {
        return rtrim(rtrim(number_format((float) $gb, 2, '.', ''), '0'), '.');
    }

    protected function diskLimitFromCli(string $app): ?string
    {
        $result = $this->cli->run('app limits ' . escapeshellarg($app));
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app limits failed');
        }

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $result['output'] ?? '');
        if (preg_match('/^\s*disk:\s*([0-9]+(?:\.[0-9]+)?)\s*GB\s*$/mi', $plain, $m)) {
            return $m[1];
        }

        return null;
    }
}
