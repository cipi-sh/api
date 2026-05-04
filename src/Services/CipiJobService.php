<?php

namespace CipiApi\Services;

use CipiApi\Exceptions\DisallowedCipiCommandException;
use CipiApi\Jobs\RunCipiCommand;
use CipiApi\Models\CipiJob;
use Illuminate\Http\Request;

class CipiJobService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @throws DisallowedCipiCommandException If {@see CipiCliService::commandIsPermitted} is false (misconfiguration).
     */
    public function dispatch(string $type, string $command, array $params = []): CipiJob
    {
        if (! $this->cli->commandIsPermitted($command)) {
            throw new DisallowedCipiCommandException($command);
        }

        $job = CipiJob::create([
            'type' => $type,
            'app' => $this->resolveApp($params),
            'params' => $params,
            'status' => 'pending',
            'triggered_by' => 'api',
            'token_id' => $this->resolveTokenId(),
        ]);

        RunCipiCommand::dispatch($job->id, $command);

        return $job;
    }

    protected function resolveApp(array $params): ?string
    {
        $app = $params['app'] ?? $params['user'] ?? null;
        if (! is_string($app) || $app === '') {
            return null;
        }
        return $app;
    }

    protected function resolveTokenId(): ?int
    {
        try {
            /** @var Request|null $request */
            $request = app('request');
            if (! $request instanceof Request) {
                return null;
            }
            $token = $request->user()?->currentAccessToken();
            if (! $token) {
                return null;
            }
            return (int) ($token->id ?? 0) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
