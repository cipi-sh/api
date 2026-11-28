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
        // Cipi CLI ≥ 5.18.0: SFTP destinations only (no secret: the key is made
        // on the server). `backup destination add|edit|remove` take S3 secrets.
        // Cipi CLI ≥ 5.19.0: backups set up and managed from the panel. A
        // secret key goes on standard input (runWithInput, --secret-stdin):
        // never on this command line, never in a job.
        'backup configure ',
        'backup destination add ',
        'backup destination edit ',
        'backup destination remove ',
        'backup key show --raw',
        'backup key rotate',
        'backup prune',
        'backup sftp add ',
        'backup sftp edit ',
        'backup sftp key ',
        'backup sftp remove ',
        // Cipi CLI ≥ 5.18.0: how the server updates Cipi (not the update itself).
        'self-update policy --json',
        'self-update policy ',
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
        // Cipi CLI ≥ 5.17.0 (API sudoers ≥ 5.17.0): the nginx page cache of an
        // app; an Octane app's PHP by its number and its restart; how
        // notifications read; Valkey per app. `valkey flush-shared` is spelled
        // out, and what it prints (a password) is never returned.
        'cache --json',
        'cache ',
        'frankenphp php ',
        'frankenphp restart ',
        'notification timezone ',
        'notification server-name ',
        'valkey list --json',
        'valkey isolate ',
        'valkey share ',
        'valkey restart ',
        'valkey flush-shared --yes',
        // Cipi CLI ≥ 5.18.0 (API sudoers ≥ 5.18.0): the mesh network of Zero
        // Trust, each command spelled out as the sudoers have it. From the
        // panel the CLI ignores --force, and refuses a lock or a leave that
        // would cut the caller off. Nothing of Cloudflare's side: `zt status`.
        'zt tailscale enable ',
        'zt tailscale disable --yes',
        'zt tailscale lock ssh --yes',
        'zt tailscale lock http --yes',
        'zt tailscale unlock ssh',
        'zt tailscale unlock http',
        'zt tailscale ssh enable',
        'zt tailscale ssh disable',
        'zt netbird enable ',
        'zt netbird disable --yes',
        'zt netbird lock ssh --yes',
        'zt netbird lock http --yes',
        'zt netbird unlock ssh',
        'zt netbird unlock http',
        // Cipi CLI ≥ 5.18.0: moving apps to another server from the panel. The
        // transfer makes its own passphrase (--auto-passphrase): none goes
        // through here. Not export, import or list: no archive at a path.
        'sync pubkey --raw',
        'sync check ',
        'sync trust ',
        'sync untrust ',
        'sync push ',
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
     * run(), with $input written to the command's standard input — a secret
     * the CLI reads with --secret-stdin. It is never on the command line (ps,
     * logs), never in a job, never in the answer: the CLI does not print it.
     *
     * @return array{output: string, exit_code: int, success: bool}
     */
    public function runWithInput(string $command, string $input): array
    {
        if (! $this->commandIsPermitted($command)) {
            return ['output' => "Command not allowed: {$command}", 'exit_code' => 1, 'success' => false];
        }
        if (! function_exists('proc_open')) {
            return ['output' => 'proc_open is disabled: a secret cannot be handed to the CLI', 'exit_code' => 1, 'success' => false];
        }
        $process = @proc_open($this->shellLine($command), [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return ['output' => 'The CLI could not be started', 'exit_code' => 1, 'success' => false];
        }
        fwrite($pipes[0], str_replace(["\r", "\n"], '', $input) . "\n");
        fclose($pipes[0]);
        $buffer = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return ['output' => self::asExec($buffer), 'exit_code' => $exitCode, 'success' => $exitCode === 0];
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
