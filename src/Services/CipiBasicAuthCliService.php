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
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi basicauth enable failed');
        }

        $parsed = $this->parser->parse('basicauth-enable', $result['output'], true);

        return array_merge(['enabled' => true, 'users' => []], is_array($parsed) ? $parsed : []);
    }

    /**
     * @return array{enabled: bool, users: list<string>}
     */
    public function disable(string $app): array
    {
        $result = $this->cli->run('basicauth disable ' . escapeshellarg($app));
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi basicauth disable failed');
        }

        $parsed = $this->parser->parse('basicauth-disable', $result['output'], true);

        return array_merge(['enabled' => false, 'users' => []], is_array($parsed) ? $parsed : []);
    }

    /**
     * @return array{enabled: bool, users: list<string>}
     */
    public function status(string $app): array
    {
        $result = $this->cli->run('basicauth status ' . escapeshellarg($app));
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi basicauth status failed');
        }

        $parsed = $this->parser->parse('basicauth-status', $result['output'], true);

        return array_merge(['enabled' => false, 'users' => []], is_array($parsed) ? $parsed : []);
    }
}
