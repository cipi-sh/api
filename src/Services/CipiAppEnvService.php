<?php

namespace CipiApi\Services;

/**
 * Manage Laravel app `.env` key/value pairs via `sudo cipi app env …` on the host.
 * Requires Cipi CLI ≥ 5.0.3 (non-interactive --show/--set/--unset/--json).
 */
class CipiAppEnvService
{
    /** `--order` (the panel's drag and drop): Cipi 5.13.0. */
    public const MIN_CIPI_ORDER = '5.13.0';

    /** `--raw` / `--stdin` (the file as it is, the panel's text editor): Cipi 5.19.2. */
    public const MIN_CIPI_RAW = '5.19.2';

    /** What `cipi app env --stdin` takes at most. */
    public const MAX_RAW_BYTES = 65536;

    public function __construct(
        protected CipiCliService $cli,
        protected CipiValidationService $validator,
        protected ?CipiVersionService $version = null,
    ) {}

    /**
     * @return array{app: string, vars: array<string, string>}
     */
    public function show(string $app): array
    {
        $this->assertLaravelApp($app);

        $result = $this->cli->run('app env ' . escapeshellarg($app) . ' --show --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim($result['output'] ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app env --show failed');
        }

        return [
            'app' => $app,
            'vars' => $this->decodeJsonObject($result['output'] ?? ''),
        ];
    }

    /**
     * Set and unset keys, then put the keys in $order when given: every key
     * the file has once the set/unset are done, each once (`cipi app env
     * --order`, Cipi 5.13.0). The vars come back in the file's order.
     *
     * @param  array<string, string>  $set
     * @param  list<string>  $unset
     * @param  list<string>|null  $order
     * @return array{app: string, vars: array<string, string>}
     */
    public function update(string $app, array $set = [], array $unset = [], ?array $order = null): array
    {
        $this->assertLaravelApp($app);

        if ($set === [] && $unset === [] && ($order === null || $order === [])) {
            throw new \InvalidArgumentException('Provide at least one of set, unset or order');
        }
        if ($order !== null && $order !== []) {
            $seen = [];
            foreach ($order as $key) {
                if (! is_string($key)) {
                    throw new \InvalidArgumentException('order must be a list of keys');
                }
                $this->assertEnvKey($key);
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException("{$key} is twice in order");
                }
                $seen[$key] = true;
            }
            if ($this->version !== null && ! $this->version->atLeast(self::MIN_CIPI_ORDER)) {
                throw new \InvalidArgumentException('Reordering .env needs Cipi ' . self::MIN_CIPI_ORDER . '+ on the server (cipi self-update)');
            }
        }

        foreach (array_keys($set) as $key) {
            $this->assertEnvKey((string) $key);
            if (! is_scalar($set[$key]) && $set[$key] !== null) {
                throw new \InvalidArgumentException("Env value for '{$key}' must be a string");
            }
        }
        foreach ($unset as $key) {
            $this->assertEnvKey((string) $key);
        }

        $parts = ['app env', escapeshellarg($app)];
        foreach ($set as $key => $value) {
            $parts[] = '--set=' . escapeshellarg($key . '=' . (string) $value);
        }
        foreach ($unset as $key) {
            $parts[] = '--unset=' . escapeshellarg((string) $key);
        }
        if ($order !== null && $order !== []) {
            $parts[] = '--order=' . escapeshellarg(implode(',', $order));
        }
        $parts[] = '--json';

        $result = $this->cli->run(implode(' ', $parts));
        if ($result['exit_code'] !== 0) {
            $detail = trim(preg_replace('/\x1b\[[0-9;]*m/', '', (string) ($result['output'] ?? '')) ?? '');
            // The core's own refusal of an order (a key left out, unknown…): the caller's to fix.
            if (str_contains($detail, 'Order not applied')) {
                throw new \InvalidArgumentException($detail);
            }
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app env update failed');
        }

        return [
            'app' => $app,
            'vars' => $this->decodeJsonObject($result['output'] ?? ''),
        ];
    }

    /**
     * The .env as it is: every line, comment, blank line and space
     * (`cipi app env --raw --json`).
     *
     * @return array{app: string, text: string}
     */
    public function raw(string $app): array
    {
        $this->assertLaravelApp($app);
        $this->assertRawSupported();

        $result = $this->cli->run('app env ' . escapeshellarg($app) . ' --raw --json');
        if ($result['exit_code'] !== 0) {
            $detail = trim(preg_replace('/\x1b\[[0-9;]*m/', '', (string) ($result['output'] ?? '')) ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app env --raw failed');
        }

        return ['app' => $app, 'text' => $this->decodeText($result['output'] ?? '')];
    }

    /**
     * Replace the whole .env with $text, written byte for byte — nothing
     * parsed, quoted, trimmed or moved (`cipi app env --stdin`). The text
     * goes on the CLI's standard input, never on its command line.
     *
     * @return array{app: string, text: string} the file as it is now
     */
    public function replaceRaw(string $app, string $text): array
    {
        $this->assertLaravelApp($app);

        if ($text === '') {
            throw new \InvalidArgumentException('The text is empty: the .env is left as it was');
        }
        if (strlen($text) > self::MAX_RAW_BYTES) {
            throw new \InvalidArgumentException('The text is too long (64 KB at most)');
        }
        if (str_contains($text, "\0")) {
            throw new \InvalidArgumentException('The text holds a NUL byte');
        }
        $this->assertRawSupported();

        $result = $this->cli->runWithText('app env ' . escapeshellarg($app) . ' --stdin --json', $text);
        if ($result['exit_code'] !== 0) {
            $detail = trim(preg_replace('/\x1b\[[0-9;]*m/', '', (string) ($result['output'] ?? '')) ?? '');
            throw new \RuntimeException($detail !== '' ? $detail : 'cipi app env --stdin failed');
        }

        return ['app' => $app, 'text' => $this->decodeText($result['output'] ?? '')];
    }

    /** An older core has no --raw/--stdin: refused here (501) instead of a CLI error. */
    protected function assertRawSupported(): void
    {
        if ($this->version !== null && ! $this->version->atLeast(self::MIN_CIPI_RAW)) {
            throw new EnvRawUnsupportedException('Editing the .env as text needs Cipi ' . self::MIN_CIPI_RAW . '+ on the server (cipi self-update)');
        }
    }

    protected function decodeText(string $output): string
    {
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output;
        if (! preg_match('/\{.*\}/s', $plain, $m)) {
            throw new \RuntimeException('Could not parse the .env text from cipi output');
        }
        $decoded = json_decode($m[0], true);
        if (! is_array($decoded) || ! is_string($decoded['text'] ?? null)) {
            throw new \RuntimeException('Invalid .env text from cipi');
        }

        return $decoded['text'];
    }

    public function assertEnvKey(string $key): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Invalid env key: {$key}");
        }
    }

    protected function assertLaravelApp(string $app): void
    {
        if (! $this->validator->appExists($app)) {
            throw new \RuntimeException("App '{$app}' not found");
        }
        if ($this->validator->isCustomApp($app)) {
            throw new \RuntimeException("App '{$app}' is a custom app and has no .env");
        }
    }

    /**
     * @return array<string, string>
     */
    protected function decodeJsonObject(string $output): array
    {
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output;
        if (! preg_match('/\{.*\}/s', $plain, $m)) {
            throw new \RuntimeException('Could not parse env JSON from cipi output');
        }

        $decoded = json_decode($m[0], true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid env JSON from cipi');
        }

        $vars = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $vars[$key] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }

        return $vars;
    }
}
