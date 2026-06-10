<?php

namespace CipiApi\Services;

/**
 * Reads app log snapshots matching `cipi app logs <app> [--type=T]` (non-blocking tail).
 */
class CipiAppLogsService
{
    public const TYPES = ['all', 'nginx', 'php', 'worker', 'deploy', 'laravel'];

    public function __construct(
        protected CipiLogReader $logReader,
        protected CipiValidationService $validator,
    ) {}

    public function read(string $app, string $type = 'all', int $lines = CipiLogReader::DEFAULT_LINES): string
    {
        $type = strtolower($type);
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                'Invalid type. Allowed: ' . implode(', ', self::TYPES)
            );
        }

        if (! $this->validator->appExists($app)) {
            throw new \RuntimeException("App '{$app}' not found");
        }

        $patterns = $this->resolvePatterns($app, $type);
        $content = $this->logReader->tailViaSudo($patterns, $lines);

        if ($content === '') {
            return "No log output found for app '{$app}' (type: {$type}).";
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    protected function resolvePatterns(string $app, string $type): array
    {
        $home = '/home/' . $app;
        $logsDir = "{$home}/logs";
        $laravelDir = "{$home}/shared/storage/logs";
        $isCustom = $this->validator->isCustomApp($app);
        $hasLaravelDir = $this->laravelLogsAvailable($laravelDir);

        return match ($type) {
            'nginx' => ["{$logsDir}/nginx-*.log"],
            'php' => ["{$logsDir}/php-fpm-*.log"],
            'worker' => ["{$logsDir}/worker-*.log"],
            'deploy' => ["{$logsDir}/deploy.log"],
            'laravel' => ($isCustom || ! $hasLaravelDir)
                ? ["{$logsDir}/*.log"]
                : ["{$laravelDir}/*.log"],
            'all' => ($isCustom || ! $hasLaravelDir)
                ? ["{$logsDir}/*.log"]
                : ["{$logsDir}/*.log", "{$laravelDir}/*.log"],
        };
    }

    protected function laravelLogsAvailable(string $laravelDir): bool
    {
        $inner = 'if [ -d ' . escapeshellarg($laravelDir) . ' ]; then echo yes; fi';
        $output = [];
        exec('sudo /bin/bash -c ' . escapeshellarg($inner), $output, $exitCode);

        return $exitCode === 0 && in_array('yes', $output, true);
    }
}
