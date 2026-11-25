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
        'app logs read',
        'app suspend',
        'app unsuspend',
        'app artisan',
        'app run',
        'app deploy-config',
        'app env',
        'app webhook recreate',
        'auth create',
        'auth edit',
        'auth show',
        'auth delete',
        'basicauth enable',
        'basicauth disable',
        'basicauth status',
        'deploy ',
        'alias add',
        'alias remove',
        'www add',
        'www force-to-root',
        'www force-from-root',
        'www clear',
        'www status',
        'ssl install',
        'ssl force',
        'db list',
        'db engines',
        'db install',
        'db create',
        'db backup',
        'db restore',
        'db password',
        'php list',
        'php install',
        'ssh list',
        'ssh add',
        'ssh remove',
        'status',
        'service list',
        'service restart',
        'smtp status',
        'smtp configure',
        'smtp enable',
        'smtp disable',
        'smtp test',
        'smtp delete',
        'health list',
        'health set',
        'health unset',
        'health check',
        'api ip-whitelist',
        // Cipi CLI ≥ 5.2.1
        'app fix-permissions',
        // Cipi CLI ≥ 5.2.2
        'search status',
        'search list',
        'search enable',
        'search disable',
        'package list',
        // Cipi CLI ≥ 5.3.0
        'monitor list',
        'zt status',
        // Cipi CLI ≥ 5.3.1 (API sudoers ≥ 5.4.1)
        'redirect set',
        'redirect enable',
        'redirect disable',
        'redirect unset',
        'redirect add',
        'redirect remove',
        'redirect list',
        'proxy add',
        'proxy remove',
        'proxy list',
        // Cipi CLI ≥ 5.4.0 (API sudoers ≥ 5.4.1)
        'node list',
        'node status',
        'node restart',
        // Cipi CLI ≥ 5.5.0 (`app limits` is older; `--disk` is 5.5.0)
        'app limits',
        'ssl dns list',
        'ssl dns set',
        'ssl dns remove',
        // Cipi CLI ≥ 5.5.0 (API sudoers ≥ 5.5.2): `disk --json`, `disk db --json`
        'disk',
        // Cipi CLI ≥ 5.6.4 (API sudoers ≥ 5.6.4): `yml plan --json`, `yml apply --yes`, `yml auto`
        'yml plan',
        'yml apply',
        'yml auto',
        // Cipi CLI ≥ 5.9.0 (API sudoers ≥ 5.9.0). `deploy --last` and
        // `deploy --history <app>` are covered by 'deploy ' above.
        'node install',
        'node default',
        'node upgrade',
        'node remove',
        'db link',
        'db unlink',
        'backup status --json',
        'backup list --json',
        'backup run --detach',
        'backup verify',
        'backup profile add',
        'backup profile edit',
        'backup profile remove',
        'backup profile enable',
        'backup profile disable',
        'backup destination test',
        // Cipi CLI ≥ 5.10.0 (API sudoers ≥ 5.10.0)
        'app jit',
        'frankenphp status',
        'frankenphp upgrade',
        // Cipi CLI ≥ 5.11.0 (API sudoers ≥ 5.11.0). Not openmetrics, prune, reset.
        'metrics overview',
        'metrics status',
        'metrics show',
        'metrics uptime',
        'metrics events',
        'metrics retention',
        'metrics probe',
        'metrics enable',
        'metrics disable',
        'metrics heartbeat',
        'basicauth agent',
        // Cipi CLI ≥ 5.12.0 (API sudoers ≥ 5.12.0): every channel and trigger.
        'notification status --json',
        'notification triggers',
        'notification add ',
        'notification update ',
        'notification remove ',
        'notification test ',
        'notification enable ',
        'notification disable ',
        // Cipi CLI ≥ 5.14.0 (API sudoers ≥ 5.14.0): the Firewall tab. Not
        // `firewall deny` (the panel opens ports and blocks addresses), not
        // `crowdsec rescue|refresh`; `crowdsec disable` only with --force,
        // which there skips the question.
        'firewall status --json',
        'firewall allow ',
        'firewall remove ',
        'firewall block ',
        'firewall unblock ',
        'firewall attempts ',
        'ban unban ',
        'crowdsec enable',
        'crowdsec disable --force',
        'crowdsec allow ',
        'crowdsec unallow ',
        // Cipi CLI ≥ 5.14.0: optional packages from the closed catalog, with
        // --yes. The CLI refuses a name outside the catalog, and ignores
        // --force and --autoremove from the panel.
        'package install ',
        'package remove ',
        // Cipi CLI ≥ 5.14.0: an app's own nginx directives. Not `nginx edit`
        // (a terminal), nor upgrade and default-server. From sudo the CLI
        // takes --content-b64 only, and a subset of nginx's language.
        'nginx show ',
        'nginx set ',
        'nginx reset ',
        // Cipi CLI ≥ 5.15.0: the visitor's address behind Cloudflare, each
        // with its arguments spelled out (the sudoers have them so too).
        'nginx real-ip status --json',
        'nginx real-ip cloudflare',
        'nginx real-ip refresh',
        'nginx real-ip off --yes',
    ];

    /**
     * Whether the given command string is permitted (matches a whitelisted prefix).
     */
    public function commandIsPermitted(string $command): bool
    {
        return $this->isAllowed($command);
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

        $output = [];
        exec($this->shellLine($command), $output, $exitCode);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    /**
     * run(), handing what the command has printed so far to $onOutput each
     * time more of it arrives — for a job, whose output can then be read while
     * it runs (a deploy takes minutes). The result is the one run() gives.
     *
     * @param  callable(string): void  $onOutput
     */
    public function runStreaming(string $command, callable $onOutput): array
    {
        if (! $this->commandIsPermitted($command) || ! function_exists('proc_open')) {
            return $this->run($command);
        }

        $process = @proc_open($this->shellLine($command), [1 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return $this->run($command);
        }

        $buffer = '';
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $buffer .= $chunk;
                try {
                    $onOutput($buffer);
                } catch (\Throwable) {
                    // Showing progress must never be what stops the command.
                }
            }
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [
            'output' => self::asExec($buffer),
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    /** The command line both run() and runStreaming() execute: stderr with stdout. */
    protected function shellLine(string $command): string
    {
        return 'sudo /usr/local/bin/cipi ' . $command . ' 2>&1';
    }

    /** Output as exec() returns it: no trailing whitespace on a line, no newline after the last. */
    public static function asExec(string $buffer): string
    {
        if ($buffer === '') {
            return '';
        }
        $lines = explode("\n", str_ends_with($buffer, "\n") ? substr($buffer, 0, -1) : $buffer);

        return implode("\n", array_map('rtrim', $lines));
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
