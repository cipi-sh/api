<?php

namespace CipiApi\Services;

/**
 * Server health snapshots via `sudo cipi status` and `sudo cipi service list` on the host.
 */
class CipiServerMonitorService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    public function status(): string
    {
        return $this->runOrThrow('status', 'cipi status failed');
    }

    public function serviceList(?string $service = null): string
    {
        if ($service !== null && $service !== '') {
            $service = trim($service);
            if (! preg_match('/^[a-z0-9.-]+$/', $service)) {
                throw new \InvalidArgumentException('Invalid service name');
            }

            return $this->runOrThrow(
                'service list ' . escapeshellarg($service),
                'cipi service list failed',
            );
        }

        return $this->runOrThrow('service list', 'cipi service list failed');
    }

    protected function runOrThrow(string $command, string $fallbackError): string
    {
        $result = $this->cli->run($command);
        $output = trim($result['output'] ?? '');

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($output !== '' ? $output : $fallbackError);
        }

        return $output !== '' ? $output : '(no output)';
    }
}
