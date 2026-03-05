<?php

namespace CipiApi\Services;

class CipiCliService
{
    /**
     * Allowed cipi subcommands that the API can invoke via sudo.
     */
    private const ALLOWED_COMMANDS = [
        'app create',
        'app edit',
        'app delete',
        'deploy',
        'alias add',
        'alias remove',
        'ssl install',
    ];

    public function run(string $command): array
    {
        if (! $this->isAllowed($command)) {
            return [
                'output' => "Command not allowed: {$command}",
                'exit_code' => 1,
                'success' => false,
            ];
        }

        $fullCommand = 'sudo /usr/local/bin/cipi ' . $command . ' 2>&1';
        $output = [];
        exec($fullCommand, $output, $exitCode);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    private function isAllowed(string $command): bool
    {
        foreach (self::ALLOWED_COMMANDS as $allowed) {
            if (str_starts_with($command, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
