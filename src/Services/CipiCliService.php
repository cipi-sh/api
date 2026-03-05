<?php

namespace CipiApi\Services;

class CipiCliService
{
    public function run(string $command): array
    {
        $fullCommand = 'sudo /usr/local/bin/cipi ' . $command . ' 2>&1';
        $output = [];
        exec($fullCommand, $output, $exitCode);
        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }
}
