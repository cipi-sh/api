<?php

namespace CipiApi\Services;

class CipiCliService
{
    /**
     * Prefixes for `cipi <command>` strings that {@see CipiJobService} may queue.
     * Must cover every command built for {@see RunCipiCommand} (controllers + MCP tools).
     */
    public const ALLOWED_COMMANDS = [
        'app create',
        'app edit',
        'app delete',
        'deploy ',
        'alias add',
        'alias remove',
        'ssl install',
        'db list',
        'db create',
        'db delete',
        'db backup',
        'db restore',
        'db password',
    ];

    /**
     * Whether the given command string is permitted (matches a whitelisted prefix).
     */
    public function commandIsPermitted(string $command): bool
    {
        return $this->isAllowed($command);
    }

    public function fullCommand(string $command): string
    {
        $binary = (string) config('cipi.cipi_binary', '/usr/local/bin/cipi');
        return 'sudo ' . $binary . ' ' . $command;
    }

    public function run(string $command): array
    {
        if (! $this->commandIsPermitted($command)) {
            return [
                'output' => "Command not allowed: {$command}",
                'exit_code' => 1,
                'success' => false,
            ];
        }

        $fullCommand = $this->fullCommand($command) . ' 2>&1';
        $output = [];
        exec($fullCommand, $output, $exitCode);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    /**
     * Run a Cipi CLI command streaming stdout/stderr to the given log file.
     * Returns the final captured output and exit code, mirroring {@see run()}.
     *
     * @param string|null $logFile Absolute path to a writable log file, or null to discard streaming.
     */
    public function runStreaming(string $command, ?string $logFile): array
    {
        if (! $this->commandIsPermitted($command)) {
            $msg = "Command not allowed: {$command}";
            if ($logFile) {
                @file_put_contents($logFile, $msg . "\n", FILE_APPEND);
            }
            return [
                'output' => $msg,
                'exit_code' => 1,
                'success' => false,
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($this->fullCommand($command), $descriptors, $pipes);
        if (! is_resource($process)) {
            return $this->run($command);
        }

        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);

        $logHandle = null;
        if ($logFile) {
            $logHandle = @fopen($logFile, 'ab');
        }

        $captured = '';
        $open = [$pipes[1], $pipes[2]];

        while ($open) {
            $read = $open;
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);
            if ($changed === false) {
                break;
            }
            foreach ($read as $stream) {
                $data = @fread($stream, 8192);
                if ($data === false || $data === '') {
                    if (@feof($stream)) {
                        @fclose($stream);
                        $open = array_filter($open, static fn ($s) => $s !== $stream);
                    }
                    continue;
                }
                $captured .= $data;
                if ($logHandle) {
                    @fwrite($logHandle, $data);
                    @fflush($logHandle);
                }
            }
        }

        foreach ([$pipes[1] ?? null, $pipes[2] ?? null] as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        if ($logHandle) {
            @fclose($logHandle);
        }

        $exitCode = proc_close($process);

        return [
            'output' => $captured,
            'exit_code' => (int) $exitCode,
            'success' => (int) $exitCode === 0,
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
