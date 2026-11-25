<?php

namespace CipiApi\Services;

/**
 * An app's own nginx directives (`cipi nginx show|set|reset`, Cipi CLI ≥ 5.14.0).
 *
 * The vhost of an app is Cipi's and is written again at every change; what an
 * app adds — headers, caching, limits, rewrites, locations — is kept apart and
 * included in its server block. The CLI tests every change with `nginx -t` and
 * puts everything back when nginx refuses it.
 *
 * Through the API the CLI takes a subset of nginx's language (it knows the
 * caller from sudo): no access_log or error_log path, no include, listen,
 * server_name, *_pass or certificate — with those a token would be root, or
 * reach another app. The whole language is `cipi nginx edit <app>` at the server.
 */
class CipiNginxCliService
{
    /** The CLI's own limit (lib/nginx.sh). */
    public const MAX_BYTES = 32768;

    public function __construct(
        protected CipiCliService $cli,
        protected CipiOutputParser $parser,
    ) {}

    /**
     * @return array{app: string, config: string, bytes: int, max_bytes: int, active: bool, suspended: bool}
     */
    public function show(string $app): array
    {
        $result = $this->cli->run('nginx show ' . escapeshellarg($app) . ' --json');
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->unsupported($this->plain($result)) ?? ($this->plain($result) ?: 'cipi nginx show failed'));
        }

        $decoded = json_decode(trim((string) ($result['output'] ?? '')), true);
        if (! is_array($decoded) || ! array_key_exists('config', $decoded)) {
            throw new \RuntimeException('Cipi CLI returned invalid JSON (Cipi CLI ≥ 5.14.0 required)');
        }

        return [
            'app' => $app,
            'config' => is_string($decoded['config']) ? $decoded['config'] : '',
            'bytes' => (int) ($decoded['bytes'] ?? 0),
            'max_bytes' => (int) ($decoded['max_bytes'] ?? self::MAX_BYTES),
            'active' => (bool) ($decoded['active'] ?? false),
            'suspended' => (bool) ($decoded['suspended'] ?? false),
        ];
    }

    /**
     * Replace the app's directives. An empty text removes them.
     *
     * @throws NginxConfigRefusedException the CLI or nginx refused the text: nothing changed
     */
    public function update(string $app, string $config): array
    {
        $config = str_replace(["\r\n", "\r"], "\n", $config);
        if (trim($config) === '') {
            return $this->reset($app);
        }

        $result = $this->cli->run('nginx set ' . escapeshellarg($app) . ' --content-b64=' . escapeshellarg(base64_encode($config)));
        $this->assertApplied($result, 'cipi nginx set failed');

        return $this->show($app);
    }

    public function reset(string $app): array
    {
        $result = $this->cli->run('nginx reset ' . escapeshellarg($app));
        $this->assertApplied($result, 'cipi nginx reset failed');

        return $this->show($app);
    }

    /** @param  array{output?: string, exit_code: int}  $result */
    protected function assertApplied(array $result, string $fallback): void
    {
        if ($result['exit_code'] === 0) {
            return;
        }

        $detail = $this->plain($result);
        if ($unsupported = $this->unsupported($detail)) {
            throw new \RuntimeException($unsupported);
        }
        // What the user can fix: the API's subset, or nginx's own verdict.
        if (preg_match('/Not accepted from the API|nginx refused the directives|too long|control character|not base64/i', $detail)) {
            throw new NginxConfigRefusedException(self::reasons($detail));
        }

        throw new \RuntimeException($detail !== '' ? $detail : $fallback);
    }

    /** A CLI before 5.14.0 does not know the command. */
    protected function unsupported(string $detail): ?string
    {
        return preg_match('/Use: cipi nginx default-server|sudo: .*(not allowed|a password is required|a terminal is required)/i', $detail)
            ? 'This server cannot edit nginx directives yet: Cipi 5.14.0+ is required (cipi self-update, then cipi api update).'
            : null;
    }

    /** @param  array{output?: string}  $result */
    protected function plain(array $result): string
    {
        return trim($this->parser->stripAnsi((string) ($result['output'] ?? '')));
    }

    /**
     * The CLI's message as lines: what was refused first, then one reason per
     * line ("line 3: unknown directive "foo"").
     *
     * @return list<string>
     */
    public static function reasons(string $detail): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $detail) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:\[?ERROR\]?:?|✗|✖|Error:)\s*/iu', '', trim($line)));
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 300);
            }
        }

        return array_slice($lines, 0, 25);
    }
}
