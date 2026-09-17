<?php

namespace CipiApi\Services;

/**
 * Per-app redirects and prefix proxies via `sudo cipi redirect …` / `sudo cipi proxy …`
 * (Cipi CLI ≥ 5.4.1 for the API sudoers entries; commands exist since 5.3.1).
 *
 * State lives in apps.json (`redirect`, `redirects[]`, `proxies[]`) and is rendered
 * into the nginx vhost by the CLI, which validates every rule (loops, collisions,
 * reserved paths, charset) and reverts on `nginx -t` failure.
 */
class CipiRoutesCliService
{
    public function __construct(
        protected CipiCliService $cli,
    ) {}

    /**
     * @return array{app: string, redirect: ?array, redirects: list<array>}
     */
    public function redirects(string $app): array
    {
        $decoded = $this->runJson('redirect list ' . escapeshellarg($app) . ' --json');

        return [
            'app' => (string) ($decoded['app'] ?? $app),
            'redirect' => is_array($decoded['redirect'] ?? null) ? $decoded['redirect'] : null,
            'redirects' => array_values(is_array($decoded['redirects'] ?? null) ? $decoded['redirects'] : []),
        ];
    }

    /**
     * @return array{app: string, proxies: list<array>}
     */
    public function proxies(string $app): array
    {
        $decoded = $this->runJson('proxy list ' . escapeshellarg($app) . ' --json');

        return [
            'app' => (string) ($decoded['app'] ?? $app),
            'proxies' => array_values(is_array($decoded['proxies'] ?? null) ? $decoded['proxies'] : []),
        ];
    }

    /**
     * Whole-app redirect: every hostname of the app redirects to $to.
     */
    public function setAppRedirect(string $app, string $to, int $code = 301, bool $keepPath = true): array
    {
        $command = 'redirect set ' . escapeshellarg($app)
            . ' --to=' . escapeshellarg($to)
            . ' --' . $code;
        if (! $keepPath) {
            $command .= ' --no-path';
        }

        $this->runOk($command, 'cipi redirect set failed');

        return $this->redirects($app);
    }

    public function toggleAppRedirect(string $app, bool $enabled): array
    {
        $verb = $enabled ? 'enable' : 'disable';
        $this->runOk('redirect ' . $verb . ' ' . escapeshellarg($app), "cipi redirect {$verb} failed");

        return $this->redirects($app);
    }

    public function unsetAppRedirect(string $app): array
    {
        $this->runOk('redirect unset ' . escapeshellarg($app), 'cipi redirect unset failed');

        return $this->redirects($app);
    }

    /**
     * Path redirect. A $from ending in `/` is a prefix match; otherwise exact.
     */
    public function addRedirect(string $app, string $from, string $to, int $code = 301, bool $keepPath = true): array
    {
        $command = 'redirect add ' . escapeshellarg($app)
            . ' ' . escapeshellarg($from)
            . ' ' . escapeshellarg($to)
            . ' --' . $code;
        if (! $keepPath) {
            $command .= ' --no-path';
        }

        $this->runOk($command, 'cipi redirect add failed');

        return $this->redirects($app);
    }

    public function removeRedirect(string $app, string $from): array
    {
        $this->runOk(
            'redirect remove ' . escapeshellarg($app) . ' ' . escapeshellarg($from),
            'cipi redirect remove failed',
        );

        return $this->redirects($app);
    }

    /**
     * Prefix reverse proxy (`location ^~ <prefix>` + proxy_pass).
     * The CLI loopback guard is never bypassed from the API (no --force).
     */
    public function addProxy(
        string $app,
        string $prefix,
        string $upstream,
        bool $stripPrefix = false,
        bool $preserveHost = false,
        int $timeout = 60,
        bool $buffering = true,
    ): array {
        $command = 'proxy add ' . escapeshellarg($app)
            . ' ' . escapeshellarg($prefix)
            . ' ' . escapeshellarg($upstream)
            . ' --timeout=' . escapeshellarg((string) $timeout);
        if ($stripPrefix) {
            $command .= ' --strip-prefix';
        }
        if ($preserveHost) {
            $command .= ' --preserve-host';
        }
        if (! $buffering) {
            $command .= ' --no-buffering';
        }

        $this->runOk($command, 'cipi proxy add failed');

        return $this->proxies($app);
    }

    public function removeProxy(string $app, string $prefix): array
    {
        $this->runOk(
            'proxy remove ' . escapeshellarg($app) . ' ' . escapeshellarg($prefix),
            'cipi proxy remove failed',
        );

        return $this->proxies($app);
    }

    protected function runOk(string $command, string $fallbackError): void
    {
        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : $fallbackError);
        }
    }

    protected function runJson(string $command): array
    {
        $result = $this->cli->run($command);
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi command failed');
        }

        $decoded = json_decode(trim($result['output'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Cipi CLI returned invalid JSON (Cipi CLI ≥ 5.3.1 required)');
        }

        return $decoded;
    }
}
