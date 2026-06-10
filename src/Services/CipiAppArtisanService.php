<?php

namespace CipiApi\Services;

/**
 * Runs Artisan on a Laravel app via `sudo cipi app artisan <app> …` on the host.
 */
class CipiAppArtisanService
{
    public const MAX_OUTPUT_CHARS = 50000;

    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
    ) {}

    /**
     * @return array{output: string, exit_code: int, success: bool}
     */
    public function run(string $app, string $command): array
    {
        if (! $this->validator->appExists($app)) {
            throw new \RuntimeException("App '{$app}' not found");
        }

        if ($this->validator->isCustomApp($app)) {
            throw new \RuntimeException("App '{$app}' is a custom app and has no Artisan");
        }

        $this->validateArtisanCommand($command);

        $result = $this->cli->run($this->buildCipiCommand($app, $command));
        $result['output'] = $this->truncateOutput($result['output'] ?? '');

        return $result;
    }

    public function validateArtisanCommand(string $command): void
    {
        $command = trim($command);
        if ($command === '') {
            throw new \InvalidArgumentException('Artisan command is required');
        }

        if (preg_match('/[;&|`$()<>\n\r\\\\]/', $command)) {
            throw new \InvalidArgumentException('Artisan command contains disallowed characters');
        }

        $parts = preg_split('/\s+/', $command) ?: [];
        if (strtolower($parts[0]) === 'tinker') {
            throw new \InvalidArgumentException('tinker is interactive; use SSH instead');
        }

        foreach ($parts as $part) {
            if (! preg_match('/^[a-zA-Z0-9:_\/.=-]+$/', $part)) {
                throw new \InvalidArgumentException("Invalid artisan argument: {$part}");
            }
        }
    }

    public function buildCipiCommand(string $app, string $command): string
    {
        $parts = preg_split('/\s+/', trim($command)) ?: [];
        $escaped = array_map(static fn (string $part) => escapeshellarg($part), $parts);

        return 'app artisan ' . escapeshellarg($app) . ' ' . implode(' ', $escaped);
    }

    protected function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_CHARS) {
            return $output;
        }

        return substr($output, -self::MAX_OUTPUT_CHARS)
            . "\n\n[... output truncated to last " . self::MAX_OUTPUT_CHARS . ' characters ...]';
    }
}
