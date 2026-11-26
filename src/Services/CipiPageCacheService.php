<?php

namespace CipiApi\Services;

/**
 * The nginx page cache of an app (`cipi cache`, Cipi CLI ≥ 5.17.0).
 *
 * nginx keeps the page PHP made and answers the next visitors with it,
 * without running PHP: `fastcgi_cache` for an app on PHP-FPM (Laravel,
 * WordPress, any PHP app), `proxy_cache` for one on Octane. It is not OPcache,
 * which is always on and keeps the compiled PHP files in memory — with OPcache
 * PHP still runs for every request; with the page cache it does not run at all
 * for a page that is kept.
 *
 * The cache respects what the app says (Cache-Control, Expires, Set-Cookie:
 * a response that sets a cookie or says private/no-store is never kept) and
 * skips signed-in visitors, carts, admin and API paths, POSTs. An app adds the
 * paths of its own that must never be kept (`exclude`: `/cart*`, `/feed`).
 * Cipi empties the cache after every deploy and rollback; from a script or a
 * custom deploy recipe: `sudo /usr/local/bin/cipi-cache-clear <app>`
 * (`clear_command` in the status).
 */
class CipiPageCacheService
{
    public const MIN_CIPI = '5.17.0';

    /** What `cipi cache` takes: a number and s, m, h or d — one second to thirty days. */
    public const TTL_PATTERN = '/^[1-9][0-9]{0,6}(s|m|h|d)$/';

    /** Megabytes or gigabytes, 16m to 100g. */
    public const SIZE_PATTERN = '/^[1-9][0-9]{0,5}(m|g)$/';

    /**
     * A path an app keeps out of its cache, as `cipi cache <app> exclude` takes
     * it: letters, digits, `. _ ~ % - /` and `*` for anything — nothing a shell,
     * nginx or a regular expression reads as its own. The leading / is optional.
     */
    public const EXCLUDE_PATTERN = '#^/?[A-Za-z0-9._~%/*-]{1,127}$#';

    public const EXCLUDE_MAX = 50;

    public const RULES = [
        'enabled' => 'required|boolean',
        'ttl' => ['sometimes', 'nullable', 'string', 'max:8'],
        'size' => ['sometimes', 'nullable', 'string', 'max:7'],
        'clear_on_deploy' => 'sometimes|nullable|boolean',
        'exclude' => ['sometimes', 'nullable', 'array', 'max:' . self::EXCLUDE_MAX],
        'exclude.*' => ['string', 'max:128'],
    ];

    /** For the list alone: an empty one keeps none of the app's own. */
    public const EXCLUDE_RULES = [
        'exclude' => ['present', 'array', 'max:' . self::EXCLUDE_MAX],
        'exclude.*' => ['string', 'max:128'],
    ];

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
        protected CipiOutputParser $parser,
    ) {}

    /**
     * Every app with its page cache.
     *
     * @return array{apps: list<array<string, mixed>>}
     */
    public function list(): array
    {
        $decoded = $this->json('cache --json');
        if (! isset($decoded['apps']) || ! is_array($decoded['apps'])) {
            throw new \RuntimeException('cipi cache returned no list (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return ['apps' => array_values($decoded['apps'])];
    }

    /**
     * @return array{app: string, supported: bool, kind: ?string, enabled: bool, active: bool, ttl: string,
     *               ttl_seconds: int, size: string, clear_on_deploy: bool, exclude: list<string>,
     *               exclude_max: int, files: int, bytes: int, path: string, suspended: bool,
     *               clear_command: string}
     */
    public function show(string $app): array
    {
        $decoded = $this->json('cache ' . escapeshellarg($app) . ' status --json');
        if (! array_key_exists('enabled', $decoded)) {
            throw new \RuntimeException('cipi cache returned no status (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    /** Error for validated input, or null. */
    public function inputError(array $input): ?string
    {
        $enabled = (bool) ($input['enabled'] ?? false);
        $given = array_filter(
            ['ttl' => $input['ttl'] ?? null, 'size' => $input['size'] ?? null, 'clear_on_deploy' => $input['clear_on_deploy'] ?? null],
            fn ($v) => $v !== null && $v !== '',
        );
        $exclude = $input['exclude'] ?? null;
        if (! $enabled && ($given !== [] || is_array($exclude))) {
            return 'ttl, size, clear_on_deploy and exclude apply when enabled is true (the list alone: PUT /apps/{name}/cache/exclude)';
        }
        if (is_array($exclude) && ($error = self::excludeError($exclude)) !== null) {
            return $error;
        }
        if (isset($given['ttl']) && self::ttlSeconds((string) $given['ttl']) === null) {
            return 'ttl: a number and s, m, h or d (30s, 10m, 1h, 7d), thirty days at most';
        }
        if (isset($given['size']) && ! self::sizeValid((string) $given['size'])) {
            return 'size: megabytes or gigabytes, 16m to 100g (256m, 2g)';
        }

        return null;
    }

    /**
     * Turn the cache on (with what is given: the rest stays as it is) or off.
     * nginx is tested before it reloads; a refusal changes nothing.
     *
     * @throws PageCacheRefusedException what the caller can act on (a Node app, nginx's verdict)
     * @throws \RuntimeException an older core, or a failure of the CLI
     */
    public function update(string $app, array $input): array
    {
        $this->version->require(self::MIN_CIPI, 'The page cache');

        $command = 'cache ' . escapeshellarg($app);
        if ($input['enabled'] ?? false) {
            $command .= ' on';
            if (! empty($input['ttl'])) {
                $command .= ' --ttl=' . escapeshellarg((string) $input['ttl']);
            }
            if (! empty($input['size'])) {
                $command .= ' --size=' . escapeshellarg((string) $input['size']);
            }
            if (isset($input['clear_on_deploy'])) {
                $command .= $input['clear_on_deploy'] ? ' --deploy-clear' : ' --no-deploy-clear';
            }
            if (is_array($input['exclude'] ?? null)) {
                // The whole list; an empty one is none (`--exclude=''`).
                $command .= ' --exclude=' . escapeshellarg(implode(',', self::excludeList($input['exclude'])));
            }
        } else {
            $command .= ' off';
        }
        $this->change($command, 'cipi cache failed');

        return $this->show($app);
    }

    /**
     * The paths of its own the app keeps out of its cache — the whole list, on
     * or off (off, it waits for the cache to be turned on). `/cart*` is
     * everything that starts so; `/feed` the path itself.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed> the status after it
     *
     * @throws PageCacheRefusedException a path that is not one, a Node app, nginx's verdict
     */
    public function exclude(string $app, array $paths): array
    {
        $this->version->require(self::MIN_CIPI, 'The page cache');
        if (($error = self::excludeError($paths)) !== null) {
            throw new PageCacheRefusedException($error);
        }

        $paths = self::excludeList($paths);
        $command = 'cache ' . escapeshellarg($app) . ' exclude '
            . ($paths === [] ? 'remove --all' : 'set ' . escapeshellarg(implode(',', $paths)));
        $this->change($command, 'cipi cache exclude failed');

        return $this->show($app);
    }

    /** What is wrong with a list of paths, or null. */
    public static function excludeError(array $paths): ?string
    {
        if (count($paths) > self::EXCLUDE_MAX) {
            return 'exclude: ' . self::EXCLUDE_MAX . ' paths at most — one with a * covers many (/shop/*)';
        }
        foreach ($paths as $path) {
            if (! is_string($path) || ! preg_match(self::EXCLUDE_PATTERN, $path)) {
                return 'exclude: a path is letters, digits, . _ ~ % - / and * for anything (/cart*, /feed, /shop/*/reviews), 128 characters at most';
            }
            if (preg_match('#^/?\*+$#', $path)) {
                return 'exclude: ' . $path . ' is every page — turn the cache off instead';
            }
        }

        return null;
    }

    /**
     * The paths as the CLI keeps them: each with its leading /, once.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function excludeList(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $path = '/' . ltrim((string) $path, '/');
            $path = (string) preg_replace('#\*{2,}#', '*', $path);
            if (! in_array($path, $out, true)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * Delete every cached page of the app. The next visit to each page runs
     * PHP and keeps it again.
     *
     * @return array<string, mixed> the status after it, with `cleared` (pages deleted; 0 when the cache is off)
     */
    public function clear(string $app): array
    {
        $this->version->require(self::MIN_CIPI, 'The page cache');

        $plain = $this->change('cache ' . escapeshellarg($app) . ' clear', 'cipi cache clear failed');
        $cleared = preg_match('/emptied\s+—\s+(\d+)\s+page/u', $plain, $m) ? (int) $m[1] : 0;

        return $this->show($app) + ['cleared' => $cleared];
    }

    public static function ttlSeconds(string $ttl): ?int
    {
        if (! preg_match(self::TTL_PATTERN, $ttl, $m)) {
            return null;
        }
        $seconds = (int) $ttl * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[1]];

        return $seconds >= 1 && $seconds <= 2592000 ? $seconds : null;
    }

    public static function sizeValid(string $size): bool
    {
        if (! preg_match(self::SIZE_PATTERN, $size, $m)) {
            return false;
        }
        $mb = (int) $size * ($m[1] === 'g' ? 1024 : 1);

        return $mb >= 16 && $mb <= 102400;
    }

    /** Runs a change; the CLI's plain output when it went through. */
    protected function change(string $command, string $fallback): string
    {
        $result = $this->cli->run($command);
        $plain = $this->plain($result);
        if ($result['exit_code'] === 0) {
            return $plain;
        }
        if ($unsupported = $this->unsupported($plain)) {
            throw new \RuntimeException($unsupported);
        }
        $message = $this->firstError($plain) ?? $fallback;
        // What the user can act on: the kind of app, a value, nginx's own verdict.
        if (preg_match('/Node app|Invalid|nginx refused|nginx -t|not found|suspended|not a path|Too many paths|every page|wants paths/i', $plain)) {
            throw new PageCacheRefusedException($message);
        }

        throw new \RuntimeException($message);
    }

    /** @return array<string, mixed> */
    protected function json(string $command): array
    {
        $this->version->require(self::MIN_CIPI, 'The page cache');

        $result = $this->cli->run($command);
        $plain = $this->plain($result);
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException($this->unsupported($plain) ?? $this->firstError($plain) ?? 'cipi cache failed');
        }
        $decoded = CipiAppJitService::decode((string) ($result['output'] ?? ''));
        if ($decoded === null) {
            throw new \RuntimeException('Cipi CLI returned invalid JSON (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }

    /** The API's sudoers are older than the core: `cipi cache` is not in them yet. */
    protected function unsupported(string $detail): ?string
    {
        return preg_match('/sudo: .*(not allowed|a password is required|a terminal is required)|Unknown command/i', $detail)
            ? 'This server cannot manage the page cache yet: Cipi ' . self::MIN_CIPI . '+ is required (cipi self-update, then cipi api update).'
            : null;
    }

    protected function firstError(string $plain): ?string
    {
        if (preg_match('/^\s*(?:\[ERROR\]|✗|✖|Error:)\s*(.+)$/mu', $plain, $m)) {
            return mb_substr(trim($m[1]), 0, 400);
        }
        $line = trim((string) strtok($plain, "\n"));

        return $line !== '' ? mb_substr($line, 0, 400) : null;
    }

    /** @param  array{output?: string}  $result */
    protected function plain(array $result): string
    {
        return trim($this->parser->stripAnsi((string) ($result['output'] ?? '')));
    }
}
