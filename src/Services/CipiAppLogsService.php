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
        protected CipiCliService $cli,
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
     * @return array{
     *     app: string,
     *     type: string,
     *     page: int,
     *     per_page: int,
     *     available_types: list<string>,
     *     files: list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>,
     *     warnings?: list<string>
     * }
     */
    public function readPaginated(string $app, string $type = 'all', int $page = 1, int $perPage = CipiLogReader::DEFAULT_PER_PAGE): array
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

        $page = $this->logReader->clampPage($page);
        $perPage = $this->logReader->clampPerPage($perPage);

        $files = $this->readPaginatedViaCli($app, $type, $page, $perPage);
        $warnings = [];

        if ($files === []) {
            $patterns = $this->resolvePatterns($app, $type);
            $files = $this->logReader->tailPaginatedViaSudo($patterns, $page, $perPage);
        }

        if ($files === []) {
            $warnings[] = 'No log output returned. On the server run: cipi self-update && cipi api update, then test: sudo -u www-data sudo /usr/local/bin/cipi app logs read '
                .$app.' --type='.$type.' --page=1 --per-page=20';
        }

        $payload = [
            'app' => $app,
            'type' => $type,
            'page' => $page,
            'per_page' => $perPage,
            'available_types' => $this->availableTypes($app),
            'files' => $files,
        ];

        if ($warnings !== []) {
            $payload['warnings'] = $warnings;
        }

        return $payload;
    }

    /**
     * @return list<array{path: string, total_lines: int, page: int, per_page: int, total_pages: int, lines: list<string>}>
     */
    protected function readPaginatedViaCli(string $app, string $type, int $page, int $perPage): array
    {
        $command = 'app logs read '
            .escapeshellarg($app)
            .' --type='.$type
            .' --page='.$page
            .' --per-page='.$perPage;

        $result = $this->cli->run($command);
        $output = trim($result['output'] ?? '');

        if (! $result['success'] || $output === '') {
            return [];
        }

        return $this->logReader->parsePaginatedOutput($output, $page, $perPage);
    }

    /**
     * @return list<string>
     */
    public function availableTypes(string $app): array
    {
        if (! $this->validator->appExists($app)) {
            throw new \RuntimeException("App '{$app}' not found");
        }

        return ['nginx', 'php', 'worker', 'deploy', 'laravel'];
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

        return match ($type) {
            'nginx' => ["{$logsDir}/nginx-*.log"],
            'php' => ["{$logsDir}/php-fpm-*.log"],
            'worker' => ["{$logsDir}/worker-*.log"],
            'deploy' => ["{$logsDir}/deploy.log"],
            'laravel' => $isCustom
                ? ["{$laravelDir}/*.log", "{$logsDir}/*.log"]
                : ["{$laravelDir}/*.log"],
            'all' => $isCustom
                ? ["{$laravelDir}/*.log", "{$logsDir}/*.log"]
                : ["{$laravelDir}/*.log", "{$logsDir}/*.log"],
        };
    }
}
