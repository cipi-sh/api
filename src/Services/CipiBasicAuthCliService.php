<?php

namespace CipiApi\Services;

/**
 * Manages HTTP Basic Auth for apps via `sudo cipi basicauth …` on the host.
 */
class CipiBasicAuthCliService
{
    public function __construct(
        protected CipiCliService $cli,
        protected CipiOutputParser $parser,
    ) {}

    /**
     * @return array{enabled: bool, users: list<string>, user?: string, password?: string}
     */
    public function enable(string $app, ?string $user = null, ?string $password = null): array
    {
        $args = ['basicauth enable', escapeshellarg($app)];
        if ($user !== null && $user !== '') {
            $args[] = '--user=' . escapeshellarg($user);
        }
        if ($password !== null && $password !== '') {
            $args[] = '--password=' . escapeshellarg($password);
        }

        $result = $this->cli->run(implode(' ', $args));
        $parsed = $this->parser->parse('basicauth-enable', (string) ($result['output'] ?? ''), true);
        if ($result['exit_code'] !== 0) {
            // Cipi 5.11.0–5.12.x enabled it, then exited 1 before printing
            // the credentials (a vhost without the cipi/agent marker).
            if (is_array($parsed) && preg_match('/Basic auth enabled/i', $this->parser->stripAnsi((string) $result['output']))) {
                return array_merge(['enabled' => true, 'users' => []], $parsed, [
                    'notice' => 'Enabled, but this Cipi stopped before printing the credentials: set the password again, or update Cipi (cipi self-update).',
                ]);
            }
            $detail = trim($this->parser->stripAnsi((string) ($result['output'] ?? '')));
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi basicauth enable failed');
        }

        return array_merge(['enabled' => true, 'users' => []], is_array($parsed) ? $parsed : []);
    }

    /**
     * @return array{enabled: bool, users: list<string>}
     */
    public function disable(string $app): array
    {
        $result = $this->cli->run('basicauth disable ' . escapeshellarg($app));
        if ($result['exit_code'] !== 0) {
            $detail = trim($this->parser->stripAnsi((string) ($result['output'] ?? '')));
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi basicauth disable failed');
        }

        $parsed = $this->parser->parse('basicauth-disable', $result['output'], true);

        return array_merge(['enabled' => false, 'users' => []], is_array($parsed) ? $parsed : []);
    }

    /**
     * `cipi basicauth status <app> --json` (Cipi 5.13.0), or the text of an
     * older core. Cipi 5.11.0–5.12.x printed the status and then exited 1 on
     * every app whose vhost has no cipi/agent marker: a text that holds the
     * status is the answer whatever the exit code.
     *
     * @return array{enabled: bool, users: list<string>, agent?: array{mode: string, public_prefix: string|null}}
     */
    public function status(string $app): array
    {
        $result = $this->cli->run('basicauth status ' . escapeshellarg($app) . ' --json');
        $json = json_decode(trim((string) ($result['output'] ?? '')), true);
        if ($result['exit_code'] === 0 && is_array($json) && array_key_exists('enabled', $json)) {
            return [
                'enabled' => (bool) $json['enabled'],
                'users' => array_values(array_filter(array_map('strval', is_array($json['users'] ?? null) ? $json['users'] : []), fn ($u) => $u !== '')),
                'agent' => is_array($json['agent'] ?? null) ? [
                    'mode' => (string) ($json['agent']['mode'] ?? 'public'),
                    'public_prefix' => isset($json['agent']['public_prefix']) ? (string) $json['agent']['public_prefix'] : null,
                ] : null,
            ];
        }

        // An older core prints its text whatever --json says; one that
        // refuses the flag is asked again without it.
        if (! is_array($json)) {
            $parsed = $this->parser->parse('basicauth-status', (string) ($result['output'] ?? ''), true);
            if (! is_array($parsed)) {
                $result = $this->cli->run('basicauth status ' . escapeshellarg($app));
                $parsed = $this->parser->parse('basicauth-status', (string) ($result['output'] ?? ''), true);
            }
            if (is_array($parsed)) {
                return array_merge(['enabled' => false, 'users' => []], $parsed);
            }
        }

        $detail = trim($this->parser->stripAnsi((string) ($result['output'] ?? '')));
        throw new \RuntimeException($detail !== '' ? $detail : 'cipi basicauth status failed');
    }
}
