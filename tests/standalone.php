<?php
// Standalone checks for the API classes that do not need the framework:
// minimal stand-ins for the three Laravel pieces they touch.
namespace Illuminate\Http {
    class UploadedFile {
        public function __construct(public string $path, public bool $ok = true) {}
        public function isValid(): bool { return $this->ok; }
        public function getSize(): int { return (int) filesize($this->path); }
        public function getRealPath(): string { return $this->path; }
        public function getErrorMessage(): string { return 'broken'; }
    }
}
namespace Illuminate\Support {
    class Str {
        public static function uuid(): string {
            $b = bin2hex(random_bytes(16));
            return sprintf('%s-%s-4%s-a%s-%s', substr($b, 0, 8), substr($b, 8, 4), substr($b, 13, 3), substr($b, 17, 3), substr($b, 20, 12));
        }
    }
}
namespace Composer {
    // What Composer writes for an installed package, as far as the status service asks.
    class InstalledVersions {
        public static array $pretty = [];
        public static function isInstalled(string $package): bool { return array_key_exists($package, self::$pretty); }
        public static function getPrettyVersion(string $package): ?string { return self::$pretty[$package] ?? null; }
    }
}
namespace {
    $GLOBALS['T'] = sys_get_temp_dir() . '/cipi-api-t-' . getmypid();
    $GLOBALS['CFG'] = ['cipi.db_upload.max_mb' => 1, 'cipi.db_upload.chunk_mb' => 1, 'cipi.db_upload.spool_mb' => 1];
    function storage_path(string $p = ''): string { return $GLOBALS['T'] . '/storage/' . $p; }
    function config(string $k, mixed $d = null): mixed { return $GLOBALS['CFG'][$k] ?? $d; }
    function env(string $k, mixed $d = null): mixed { return $d; }
    $src = ($argv[1] ?? dirname(__DIR__)) . '/src/Services/';
    foreach (['CipiCliService', 'CipiVersionService', 'CipiValidationService', 'CipiDbUploadService', 'CipiBackupCliService', 'CipiDeployHistoryCliService', 'CipiServerStatusService', 'CipiAppJitService', 'CipiFrankenPhpService', 'CipiOutputParser', 'CipiMetricsService'] as $c) {
        require $src . $c . '.php';
    }

    class FakeVersion extends CipiApi\Services\CipiVersionService {
        public function __construct(public ?string $v) {}
        public function installed(): ?string { return $this->v; }
    }
    class FakeStatus extends CipiApi\Services\CipiServerStatusService {
        public ?array $fromCli = null;
        protected function snapshotFromCli(): ?array { return $this->fromCli; }
        protected function snapshotFromHost(): array { return ['system' => ['hostname' => 'host-read'], 'apps' => 0]; }
    }
    class FakeHostPhp extends CipiApi\Services\CipiServerStatusService {
        public array $active = [];
        public function php(): array { return $this->phpInfo(); }
        protected function serviceIsRunning(string $unit): bool { return in_array($unit, $this->active, true); }
        protected function phpPoolCount(string $version): int { return $version === '8.1' ? 2 : 1; }
    }
    class FakeCli extends CipiApi\Services\CipiCliService {
        public array $ran = [];
        public array $next = ['output' => '', 'exit_code' => 0, 'success' => true];
        public function run(string $command): array {
            if (! $this->commandIsPermitted($command)) { return ['output' => "Command not allowed: {$command}", 'exit_code' => 1, 'success' => false]; }
            $this->ran[] = $command;
            return $this->next;
        }
    }
    $pass = 0; $fail = 0;
    function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  OK: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
    function thrown(callable $f): ?string { try { $f(); } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); } return null; }
    function tmpfile_with(string $data): Illuminate\Http\UploadedFile { $p = tempnam($GLOBALS['T'], 'c'); file_put_contents($p, $data); return new Illuminate\Http\UploadedFile($p); }
    @mkdir($GLOBALS['T'] . '/storage/app', 0700, true);

    echo "--- dump upload\n";
    $up = new CipiApi\Services\CipiDbUploadService(new FakeVersion('5.9.0'));
    ok(str_starts_with((string) thrown(fn () => (new CipiApi\Services\CipiDbUploadService(new FakeVersion('5.8.5')))->requireCore()), 'RuntimeException: Uploading a database dump needs Cipi 5.9.0+'), 'an older core is refused before anything is stored');
    ok(thrown(fn () => (new CipiApi\Services\CipiDbUploadService(new FakeVersion(null)))->requireCore()) !== null, 'an unknown version counts as too old');
    ok(str_contains((string) thrown(fn () => $up->start('dump.zip', 10)), 'Only .sql and .sql.gz'), 'only .sql and .sql.gz');
    ok(str_contains((string) thrown(fn () => $up->start('dump.sql', 0)), 'empty'), 'an empty dump is refused');
    ok(str_contains((string) thrown(fn () => $up->start('dump.sql.gz', 1048577)), 'larger than the limit of 1 MB'), 'over the size limit: refused, and the limit is named');
    $GLOBALS['CFG']['cipi.db_upload.max_mb'] = 3; $GLOBALS['CFG']['cipi.db_upload.spool_mb'] = 4;
    $gz = gzencode(str_repeat("INSERT INTO t VALUES (1);\n", 60000), 1);
    $gz .= random_bytes(max(0, 1500000 - strlen($gz)));   // 1.5 MB that still starts with the gzip magic
    $size = strlen($gz);
    $s = $up->start('../../etc/My Dump (1).SQL.GZ', $size);
    ok(preg_match('/^[0-9a-f-]{36}$/', $s['upload_id']) === 1 && $s['chunks'] === 2 && $s['chunk_size'] === 1048576, 'start: an id, the chunk size and the number of chunks');
    $id = $s['upload_id'];
    ok($up->name($id) === 'My_Dump__1_.SQL.GZ', 'the original name is kept for messages only, sanitised: ' . $up->name($id));
    ok(str_contains((string) thrown(fn () => $up->finalize($id)), 'not complete'), 'restore of an incomplete upload is refused');
    ok(str_starts_with((string) thrown(fn () => $up->append($id, 1, tmpfile_with('x'))), 'LogicException: Chunk 1 is out of order: the next one is 0'), 'a chunk out of order names the one expected');
    $r = $up->append($id, 0, tmpfile_with(substr($gz, 0, 1048576)));
    ok($r['received'] === 1048576 && $r['next'] === 1 && $r['complete'] === false, 'chunk 0 appended');
    ok(str_starts_with((string) thrown(fn () => $up->append($id, 0, tmpfile_with('x'))), 'LogicException'), 'the same chunk twice is refused');
    ok(str_contains((string) thrown(fn () => $up->append($id, 1, tmpfile_with(substr($gz, 1048576) . 'EXTRA'))), 'More data than the size declared'), 'more data than declared is refused');
    ok(str_contains((string) thrown(fn () => $up->append($id, 1, tmpfile_with(str_repeat('a', 1048577)))), 'A chunk must be between'), 'a chunk over the chunk size is refused');
    $r = $up->append($id, 1, tmpfile_with(substr($gz, 1048576)));
    ok($r['complete'] === true && $r['received'] === $size, 'last chunk: complete');
    $path = $up->finalize($id);
    ok($path === $GLOBALS['T'] . '/storage/app/cipi-db-uploads/' . $id . '.sql.gz' && hash_file('sha256', $path) === hash('sha256', $gz), 'finalize: the file is what was sent, under <id>.sql.gz in the API storage');
    ok($up->finalize($id) === $path, 'finalize twice gives the same path');
    ok((fileperms($path) & 0777) === 0600, 'the dump is readable by the API user only');
    ok(str_contains((string) thrown(fn () => $up->start('second.sql', 3000000)), 'Too many uploads'), 'the spool has a limit of its own');
    $up->discard($id);
    ok(! file_exists($path) && glob($up->dir() . '/' . $id . '*') === [], 'discard removes the dump and its record');
    ok(str_starts_with((string) thrown(fn () => $up->finalize($id)), 'OutOfBoundsException'), 'a discarded upload is unknown');
    ok(str_starts_with((string) thrown(fn () => $up->append('../../etc/passwd', 0, tmpfile_with('x'))), 'OutOfBoundsException'), 'an id that is not a UUID is unknown (no path is built from it)');
    $up->discard('../../etc/passwd');
    $s2 = $up->start('plain.sql.gz', 5); $up->append($s2['upload_id'], 0, tmpfile_with('hello'));
    ok(str_contains((string) thrown(fn () => $up->finalize($s2['upload_id'])), 'not gzip-compressed'), 'a .sql.gz that is not gzip is refused');
    $up->discard($s2['upload_id']);
    $s3 = $up->start('zipped.sql', 20); $up->append($s3['upload_id'], 0, tmpfile_with(substr($gz, 0, 20)));
    ok(str_contains((string) thrown(fn () => $up->finalize($s3['upload_id'])), 'upload it as .sql.gz'), 'a gzip named .sql is refused (it would be imported as text)');
    $s4 = $up->start('old.sql', 3); touch($up->dir() . '/' . $s4['upload_id'] . '.part', time() - 90000); touch($up->dir() . '/' . $s4['upload_id'] . '.json', time() - 90000);
    $up->sweep();
    ok(glob($up->dir() . '/' . $s4['upload_id'] . '*') === [], 'what is older than a day is swept');

    echo "--- backup profiles\n";
    $cli = new FakeCli; $bk = new CipiApi\Services\CipiBackupCliService($cli, new FakeVersion('5.9.0'));
    $f = $bk->profileFlags(['scope' => 'db', 'databases' => ['shop', 'shop_*'], 'exclude_databases' => [], 'exclude_tables' => ['*.jobs'], 'every' => '30m', 'destinations' => ['local', 'offsite'], 'keep' => 48, 'encrypt' => true], false);
    ok($f['errors'] === [] && $f['flags'] === ["--scope='db'", "--databases='shop,shop_*'", "--exclude-tables='*.jobs'", "--every='30m'", "--dest='local,offsite'", '--keep=48', '--keep-days=0', '--keep-weeks=0', '--encrypt'], 'create: flags, every value quoted; retention travels whole: ' . implode(' ', $f['flags']));
    $f = $bk->profileFlags(['exclude_databases' => [], 'exclude_tables' => '', 'encrypt' => false], true);
    ok($f['flags'] === ['--exclude-databases=', '--exclude-tables=', '--no-encrypt'], 'edit: an emptied exclusion list is sent empty: ' . implode(' ', $f['flags']));
    $f = $bk->profileFlags(['apps' => []], true);
    ok($f['flags'] === [] && $f['errors'] === [], 'an empty selector is not sent (the CLI would refuse a profile that takes nothing)');
    foreach ([
        'scope' => ['scope' => 'everything'],
        'apps' => ['apps' => ['ok', 'bad name; rm -rf /']],
        'databases' => ['databases' => ['$(id)']],
        'exclude_tables' => ['exclude_tables' => ["x' --dest=evil"]],
        'every' => ['every' => '30 minutes'],
        'cron' => ['cron' => "0 2 * * *\n* * * * * root id"],
        'destinations' => ['destinations' => ['local', 'Bad Dest']],
        'keep' => ['keep' => 0, 'keep_days' => 0],
        'keep_days' => ['keep_days' => '7; id'],
    ] as $field => $in) {
        $f = $bk->profileFlags($in, true);
        ok(isset($f['errors'][$field]) && $f['flags'] === [], "invalid {$field} is an error and builds no flag");
    }
    ok(isset($bk->profileFlags(['every' => '1d', 'cron' => '0 2 * * *'], true)['errors']['every']), 'every and cron together are refused');
    ok($bk->profileFlags(['cron' => '*/15 2-4 * * 1,3'], true)['flags'] === ["--cron='*/15 2-4 * * 1,3'"], 'a five-field cron passes');
    $bk->saveProfile('fast-db', ["--scope='db'"], false);
    $bk->run('fast-db'); $bk->run(null); $bk->toggle('fast-db', false); $bk->remove('fast-db'); $bk->testDestinations('offsite');
    $cli->next = ['output' => '{"profiles":{},"errors":[]}', 'exit_code' => 0, 'success' => true]; $runs = $bk->runs('fast-db');
    $cli->next = ['output' => '{"configured":true,"profiles":[]}', 'exit_code' => 0, 'success' => true]; $st = $bk->status();
    ok($cli->ran === ["backup profile add 'fast-db' --scope='db'", "backup run --detach --profile='fast-db'", 'backup run --detach', "backup profile disable 'fast-db'", "backup profile remove 'fast-db'", "backup destination test 'offsite'", "backup list --json --profile='fast-db'", 'backup status --json'], 'every command the service builds is on the allow-list, and a run is always detached');
    ok($st['configured'] === true && $runs['profiles'] instanceof stdClass, 'status and runs are decoded; an empty profiles map stays an object');
    $cli->next = ['output' => "\e[0;31m[ERROR]\e[0m No such profile: nope", 'exit_code' => 1, 'success' => false];
    ok(thrown(fn () => $bk->toggle('nope', true)) === 'RuntimeException: No such profile: nope', "the CLI's refusal comes back as its message, without colours");
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiBackupCliService(new FakeCli, new FakeVersion('5.8.5')))->status()), 'needs Cipi 5.9.0+'), 'an older core is refused');
    $n = count($cli->ran); $r = $cli->run('backup configure'); $r2 = $cli->run('backup destination add x --secret=s'); $r3 = $cli->run('backup run --profile=x'); $r4 = $cli->run('backup key show');
    ok(! $r['success'] && ! $r2['success'] && ! $r3['success'] && ! $r4['success'] && count($cli->ran) === $n, 'not on the allow-list: backup configure, destination add, key, a run that is not detached');

    echo "--- backup status (1.39.1)\n";
    $cli = new FakeCli; $bk = new CipiApi\Services\CipiBackupCliService($cli, new FakeVersion('5.9.0'));
    $answer = fn (string $output, int $exit = 0) => $cli->next = ['output' => $output, 'exit_code' => $exit, 'success' => $exit === 0];
    $configured = '{"configured": true, "local_dir": "/var/backups/cipi", "destinations": [{"name": "local"}], "profiles": [{"name": "nightly"}]}';
    $answer($configured);
    $st = $bk->status();
    ok($st['configured'] === true && $st['profiles'][0]['name'] === 'nightly' && $st['config_file'] === '/etc/cipi/backup.json' && $st['host'] === gethostname(), 'a configured server: the CLI\'s answer, plus the host and the file it looks for');
    ok(end($cli->ran) === 'backup status --json', 'asked with: cipi backup status --json');
    $answer('{"configured": false, "profiles": [], "destinations": []}');
    $st = $bk->status();
    ok($st['configured'] === false && $st['config_file'] === '/etc/cipi/backup.json' && is_string($st['host']), '"not configured" is passed on only when the CLI says it, with where it looked');
    $answer('{"configured": false, "config_file": "/srv/cipi/backup.json", "host": "from-the-cli"}');
    $st = $bk->status();
    ok($st['config_file'] === '/srv/cipi/backup.json' && $st['host'] === 'from-the-cli', 'a core that says where it looked is believed over the default');
    foreach (['an empty object' => '{}', 'an empty list' => '[]', 'another shape' => '{"profiles": {}, "errors": []}', 'a flag that is not one' => '{"configured": "yes"}', 'a null flag' => '{"configured": null}'] as $what => $out) {
        $answer($out);
        ok(str_starts_with((string) thrown(fn () => $bk->status()), 'RuntimeException: cipi backup status --json answered without a backup status'), "{$what} is refused: no client can read \"not configured\" out of it");
    }
    $answer("sudo: unable to resolve host fra1: Name or service not known\n" . $configured . "\n");
    ok(($bk->status()['configured'] ?? null) === true, 'a line sudo prints before the JSON does not turn the answer into "no JSON"');
    $answer($configured . "\n\033[2m→ shop (default app — cipi use)\033[0m");
    ok(($bk->status()['configured'] ?? null) === true, 'nor does one after it');
    $answer("\n  Backup\n  Not configured — run: cipi backup configure\n");
    $e = (string) thrown(fn () => $bk->status());
    ok(str_contains($e, 'returned no JSON') && str_contains($e, 'it printed "Backup Not configured'), 'text instead of JSON is an error that quotes what was printed: ' . substr($e, 18, 90) . '…');
    $answer('');
    ok(str_contains((string) thrown(fn () => $bk->status()), 'it printed nothing'), 'so is no output at all');
    $answer("sudo: a terminal is required to read the password; either use the -S option to read from standard input or configure an askpass helper\nsudo: a password is required", 1);
    $e = (string) thrown(fn () => $bk->status());
    ok(str_contains($e, 'sudo refused `cipi backup status` for the panel') && str_contains($e, '/etc/sudoers.d/cipi-api') && str_contains($e, 'a terminal is required'), 'sudo refusing the command is said as that, with the file to look at');
    $answer("Sorry, user www-data is not allowed to execute '/usr/local/bin/cipi backup status --json' as root on fra1.", 1);
    ok(str_contains((string) thrown(fn () => $bk->status()), 'sudo refused `cipi backup status`'), 'the same for "not allowed to execute"');
    $answer("sudo-rs: I'm sorry www-data. I'm afraid I can't do that", 1);
    ok(str_contains((string) thrown(fn () => $bk->status()), 'sudo refused `cipi backup status`'), 'and for sudo-rs');
    $answer("sudo: unable to resolve host fra1\n[ERROR] vault: failed to decrypt backup.json", 1);
    ok(thrown(fn () => $bk->status()) === 'RuntimeException: vault: failed to decrypt backup.json', 'a warning from sudo is not a refusal: the CLI\'s own error is the one reported');
    $answer('{"profiles": {"nightly": [{"run": "2026-11-16_020000", "destinations": ["local"]}]}, "errors": []}');
    ok(($bk->runs()['profiles']['nightly'][0]['run'] ?? '') === '2026-11-16_020000', 'the runs are read as before');

    echo "--- status: the API says its own version (1.39.1)\n";
    $statusOf = function (?array $fromCli): array {
        $svc = (new ReflectionClass(FakeStatus::class))->newInstanceWithoutConstructor();
        $svc->fromCli = $fromCli;

        return $svc->snapshot();
    };
    Composer\InstalledVersions::$pretty = ['cipi/api' => 'v1.39.1'];
    $snap = $statusOf(['system' => ['hostname' => 'fra1', 'cipi' => '5.9.0'], 'apps' => 2]);
    ok($snap['system'] === ['hostname' => 'fra1', 'cipi' => '5.9.0', 'api' => '1.39.1'] && $snap['apps'] === 2, 'GET /api/status: system.api next to system.cipi, nothing else touched');
    ok($statusOf(null)['system']['api'] === '1.39.1', 'also when the snapshot is read from the host');
    Composer\InstalledVersions::$pretty = ['cipi/api' => 'dev-main'];
    ok($statusOf(['system' => []])['system']['api'] === 'dev-main', 'a branch build says so');
    Composer\InstalledVersions::$pretty = ['cipi/api' => "1.39.1\n<script>"];
    ok(! array_key_exists('api', $statusOf(['system' => ['cipi' => '5.9.0']])['system']), 'a version that is not one is left out');
    Composer\InstalledVersions::$pretty = [];
    ok($statusOf(['system' => ['cipi' => '5.9.0']])['system'] === ['cipi' => '5.9.0'], 'a package Composer does not know (a path checkout) reports no version rather than a guess');

    echo "--- deploy history\n";
    $cli = new FakeCli; $dh = new CipiApi\Services\CipiDeployHistoryCliService($cli, new FakeVersion('5.9.0'));
    $cli->next = ['output' => '{"app":"shop","count":1,"runs":[{"result":"ok","duration":41}],"log":"/x"}', 'exit_code' => 0, 'success' => true];
    $h = $dh->history('shop', 9999);
    ok($cli->ran === ["deploy --history 'shop' --json --limit=500"] && $h['runs'][0]['duration'] === 41, 'history: the flag goes first (an older core cannot deploy on it), the limit is capped');
    $cli->next = ['output' => '{"apps":{"shop":{"result":"ok"},"blog":null}}', 'exit_code' => 0, 'success' => true];
    $l = $dh->last();
    ok(end($cli->ran) === 'deploy --last --json' && array_key_exists('blog', $l) && $l['blog'] === null, 'last: every app, null for one never deployed');
    $old = new FakeCli; $dho = new CipiApi\Services\CipiDeployHistoryCliService($old, new FakeVersion('5.8.5'));
    ok(str_contains((string) thrown(fn () => $dho->history('shop')), 'needs Cipi 5.9.0+') && str_contains((string) thrown(fn () => $dho->last()), 'needs Cipi 5.9.0+') && $old->ran === [], 'an older core: refused, and nothing is run');
    foreach (["node install '22'", "node default 'system'", 'node upgrade', "node upgrade '24' --restart", "node remove '20'", "db link 'wpblog' 'blog'", "db unlink 'wpblog' --engine='mariadb'", "db create --name='x' --app='blog'", "db list --json", "backup verify --profile='x'"] as $c) {
        if (! $cli->commandIsPermitted($c)) { ok(false, "not permitted: {$c}"); }
    }
    ok(true, 'the Node, link and verify commands the controllers build are on the allow-list');

    echo "--- PHP 8.6 (1.39.2)\n";
    $GLOBALS['CFG']['cipi.php_versions'] = (require dirname($src, 2) . '/config/cipi.php')['php_versions'];
    $php = fn (?string $core) => new CipiApi\Services\CipiValidationService(new FakeVersion($core));
    ok(in_array('8.6', $GLOBALS['CFG']['cipi.php_versions'], true) && $php('5.9.1')->phpVersionError('8.6') === null && $php('5.10.0')->isValidPhpVersion('8.6'), '8.6 is in the list and accepted on Cipi 5.9.1+');
    ok($php('5.9.0')->phpVersionError('8.6') === 'PHP 8.6 needs Cipi 5.9.1+ — this server runs Cipi 5.9.0. Run: cipi self-update', 'on an older core it is refused with the version it needs, before a job is queued');
    ok($php(null)->phpVersionError('8.6') === null, 'a core whose version cannot be read is let through (the CLI has the last word)');
    ok($php('5.8.0')->phpVersionError('8.5') === null && $php('5.8.0')->phpVersionError(null) === null, 'the other versions do not depend on the core');
    ok(str_starts_with((string) $php('5.9.1')->phpVersionError('8.7'), "Invalid PHP version '8.7'. Allowed: 8.3, 8.4, 8.5, 8.6") && $php('5.9.1')->phpVersionError('8.2') !== null, 'a version outside the list is still refused');
    ok(str_contains((string) $php('5.9.0')->phpInstalledError('8.6'), 'needs Cipi 5.9.1+'), 'app create/edit get the same answer before the "not installed" one');
    ok((new FakeCli)->commandIsPermitted("php install '8.6'"), 'php install 8.6 is on the allow-list');

    echo "--- legacy PHP (7.4–8.2) on a server installed before Cipi 4.5.4\n";
    $legacy = (string) $php('5.9.1')->phpVersionError('8.1');
    ok(str_starts_with($legacy, 'PHP 8.1 is a legacy version') && str_contains($legacy, 'Allowed: 8.3, 8.4, 8.5, 8.6'), 'a legacy version is refused as one, with what can be used instead');
    ok(str_starts_with((string) $php('5.9.1')->phpInstalledError('7.4'), 'PHP 7.4 is a legacy version'), 'app create/edit refuse it the same way, installed or not');
    ok(array_intersect(CipiApi\Services\CipiValidationService::PHP_LEGACY, $GLOBALS['CFG']['cipi.php_versions']) === [], 'no legacy version is in the list of the assignable ones');
    $host = (new ReflectionClass(FakeHostPhp::class))->newInstanceWithoutConstructor();
    $host->active = ['php8.5-fpm', 'php8.1-fpm', 'nginx'];
    ok($host->php() === [['version' => '8.1', 'status' => 'running', 'pools' => 2], ['version' => '8.5', 'status' => 'running', 'pools' => 1]], 'GET /api/status read from the host: a running legacy FPM is listed, oldest first');
    $mcp = file_get_contents(dirname($src) . '/Mcp/Tools/AppCreateTool.php');
    ok(! str_contains($mcp, "'8.4')") && ! str_contains($mcp, 'default: 8.4'), 'the MCP AppCreate tool has no PHP of its own: omitted, the server decides');
    $spec = json_decode(file_get_contents(dirname($src, 2) . '/public/api-docs/openapi.json'), true);
    $enums = [$spec['components']['schemas']['AppEditRequest']['properties']['php']['enum'] ?? null];
    array_walk_recursive($spec, function ($v, $k) use (&$seen) { if ($v === '7.4' || $v === '8.2') { $seen = true; } });
    ok($enums === [['8.3', '8.4', '8.5', '8.6']] && empty($seen), 'OpenAPI offers what the API accepts: no legacy version in any enum');

    echo "--- JIT per app, FrankenPHP builds (1.40.0, Cipi 5.10.0)\n";
    $apps = $GLOBALS['T'] . '/apps.json';
    file_put_contents($apps, json_encode(['shop' => ['php' => '8.5', 'octane' => 'frankenphp'], 'blog' => ['php' => '8.5'], 'spa' => ['php' => '8.5', 'runtime' => 'node']]));
    $GLOBALS['CFG']['cipi.apps_json'] = $apps;
    $cli = new FakeCli;
    $jit = new CipiApi\Services\CipiAppJitService($cli, new CipiApi\Services\CipiValidationService(new FakeVersion('5.10.0')), new FakeVersion('5.10.0'));
    ok($jit->command('blog', ['enabled' => true]) === "app jit 'blog' on", 'on, CLI defaults');
    ok($jit->command('blog', ['enabled' => true, 'mode' => 'function', 'buffer' => '256m']) === "app jit 'blog' on --mode='function' --buffer='256M'", 'on with mode and buffer, quoted, the size upper-cased');
    ok($jit->command('blog', ['enabled' => false]) === "app jit 'blog' off", 'off');
    ok($jit->inputError('blog', ['enabled' => true, 'buffer' => '8M']) === 'buffer must be between 16M and 1G' && $jit->inputError('blog', ['enabled' => true, 'buffer' => '2G']) !== null && $jit->inputError('blog', ['enabled' => true, 'buffer' => '1G']) === null, 'buffer: 16M to 1G, the CLI\'s bounds');
    ok($jit->inputError('blog', ['enabled' => false, 'mode' => 'tracing']) !== null, 'mode or buffer with enabled false is refused');
    ok(str_contains((string) $jit->inputError('spa', ['enabled' => true]), 'Node app'), 'a Node app is refused');
    $oldJit = new CipiApi\Services\CipiAppJitService(new FakeCli, new CipiApi\Services\CipiValidationService(new FakeVersion('5.9.1')), new FakeVersion('5.9.1'));
    ok(str_contains((string) $oldJit->inputError('blog', ['enabled' => true]), 'needs Cipi 5.10.0+'), 'an older core is refused before a job is queued');
    $cli->next = ['output' => "sudo: unable to resolve host x\n" . '{"app":"shop","runtime":"octane","php":"8.5.13","enabled":true,"mode":"tracing","buffer":"128M","supported":true,"note":null}', 'exit_code' => 0, 'success' => true];
    $st = $jit->show('shop');
    ok(end($cli->ran) === "app jit 'shop' --json" && $st['runtime'] === 'octane' && $st['php'] === '8.5.13', 'show: cipi app jit <app> --json, a sudo line around the JSON ignored');
    foreach (["app jit 'blog' on --mode='tracing' --buffer='128M'", "app jit 'blog' off", 'frankenphp status --json', "frankenphp upgrade 'shop' --yes", 'frankenphp upgrade --all --yes'] as $c) {
        ok((new FakeCli)->commandIsPermitted($c), "allow-list: {$c}");
    }
    ok(! (new FakeCli)->commandIsPermitted("frankenphp use 'shop' x --force") && ! (new FakeCli)->commandIsPermitted('frankenphp import /tmp/x'), 'not on the allow-list: frankenphp use, import');
    $fp = new CipiApi\Services\CipiFrankenPhpService($cli, new FakeVersion('5.10.0'));
    ok($fp->upgradeCommand('shop') === "frankenphp upgrade 'shop' --yes" && $fp->upgradeCommand(null) === 'frankenphp upgrade --all --yes', 'upgrade: one app, or --all');
    $cli->next = ['output' => '{"builds":[{"id":"1.13.2-php8.5.13"}],"apps":[{"app":"shop","state":"ok"}],"autoupdate":true}', 'exit_code' => 0, 'success' => true];
    $st = $fp->status();
    ok(end($cli->ran) === 'frankenphp status --json' && $st['apps'][0]['state'] === 'ok' && $st['autoupdate'] === true, 'status: decoded');
    $cli->next = ['output' => 'FrankenPHP — the binary each Octane app runs', 'exit_code' => 0, 'success' => true];
    ok(str_contains((string) thrown(fn () => $fp->status()), 'returned no status'), 'a table instead of JSON is an error');
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiFrankenPhpService(new FakeCli, new FakeVersion('5.9.1')))->upgradeCommand(null)), 'needs Cipi 5.10.0+'), 'FrankenPHP on an older core: refused');
    $abil = file_get_contents(dirname($src) . '/Services/CipiJobAbilities.php');
    ok(str_contains($abil, "'app-jit' => 'apps-edit'") && str_contains($abil, "'frankenphp-upgrade' => 'php-manage'"), 'job types have their ability');
    $parser = (new ReflectionClass(CipiApi\Services\CipiOutputParser::class))->newInstanceWithoutConstructor();
    $pj = new ReflectionMethod($parser, 'parseAppJit');
    ok($pj->invoke($parser, "✓ JIT on for 'blog' (mode=tracing, buffer=128M)") === ['app' => 'blog', 'enabled' => true, 'mode' => 'tracing', 'buffer' => '128M'] && $pj->invoke($parser, "✓ JIT off for 'blog'") === ['app' => 'blog', 'enabled' => false], 'job result: JIT on / off parsed');
    $pu = new ReflectionMethod($parser, 'parseFrankenPhpUpgrade');
    $r = $pu->invoke($parser, "✓ 'shop' runs FrankenPHP 1.13.2 on PHP 8.5.13\n[INFO] 'api' is up to date (1.14.0-php8.6.0)\n[INFO] FrankenPHP 1.14.0 is out and carries PHP 8.6.0; 'shop' is on PHP 8.5, so Cipi keeps it");
    ok($r['apps']['shop']['result'] === 'upgraded' && $r['apps']['api']['result'] === 'up-to-date' && $r['apps']['shop']['available_on_other_php']['php'] === '8.6.0' && $r['rolled_back'] === [], 'job result: per app, upgraded / up to date / a release on another PHP');
    $spec = json_decode(file_get_contents(dirname($src, 2) . '/public/api-docs/openapi.json'), true);
    ok(isset($spec['paths']['/apps/{name}/jit']['get'], $spec['paths']['/apps/{name}/jit']['put'], $spec['paths']['/frankenphp']['get'], $spec['paths']['/frankenphp/upgrade']['post']), 'OpenAPI documents the four routes');
    $routes = file_get_contents(dirname($src, 2) . '/routes/api.php');
    ok(str_contains($routes, "Route::put('/apps/{name}/jit', [AppJitController::class, 'update'])->middleware('ability:apps-edit')") && str_contains($routes, "Route::post('/frankenphp/upgrade', [FrankenPhpController::class, 'upgrade'])->middleware('ability:php-manage')"), 'routes and their abilities');

    echo "--- Metrics & health history (1.41.0, Cipi 5.11.0)\n";
    $cli = new FakeCli;
    $met = new CipiApi\Services\CipiMetricsService($cli, new FakeVersion('5.11.0'));
    ok($met->seriesCommand([]) === 'metrics show --json', 'series: host, CLI defaults');
    ok($met->seriesCommand(['app' => 'shop', 'metrics' => 'cpu,http_5xx', 'range' => '7d']) === "metrics show --app='shop' --metrics='cpu,http_5xx' --range='7d' --json", 'series: app, metrics, range — quoted');
    ok($met->seriesCommand(['metrics' => 'cpu,mem_mb', 'from' => 1795000000, 'to' => 1795086400]) === "metrics show --metrics='cpu' --from=1795000000 --to=1795086400 --json", 'series: an app metric is dropped for the host; from/to as integers');
    ok(str_starts_with((string) $met->seriesError(['metrics' => 'cpu,nope']), 'Unknown metric(s): nope'), 'series: an unknown metric is named');
    ok($met->seriesError(['to' => 1795000000]) === 'to needs from' && $met->seriesError(['from' => 1795000010, 'to' => 1795000000]) === 'to must be after from', 'series: from/to checked');
    ok(preg_match(CipiApi\Services\CipiMetricsService::RANGE_PATTERN, '30d') === 1 && preg_match(CipiApi\Services\CipiMetricsService::RANGE_PATTERN, "7d'; rm") === 0 && preg_match(CipiApi\Services\CipiMetricsService::RANGE_PATTERN, '0d') === 0, 'range pattern: 30d yes, 0d and anything else no');
    ok($met->settingsCommands(['enabled' => true, 'probe' => false, 'raw_days' => 3, 'rollup_days' => 60, 'resolution_minutes' => 30, 'events_days' => 120]) === ['metrics enable', 'metrics probe off', 'metrics retention --raw=3d --rollup=60d --events=120d --resolution=30m'], 'settings: one command per concern, retention in one');
    ok($met->settingsError([]) !== null && $met->settingsError(['raw_days' => 10, 'rollup_days' => 5]) === 'rollup_days cannot be shorter than raw_days', 'settings: nothing to change, rollup shorter than raw');
    ok($met->heartbeatCommand('https://hc-ping.com/abc', 5) === "metrics heartbeat set 'https://hc-ping.com/abc' --every=5", 'heartbeat: URL quoted');
    $hbRe = substr(CipiApi\Services\CipiMetricsService::HEARTBEAT_RULES['url'][3], 6);
    ok(preg_match($hbRe, 'https://hc-ping.com/abc-123') === 1 && preg_match($hbRe, 'https://x.test/a b') === 0 && preg_match($hbRe, 'ftp://x.test/') === 0, 'heartbeat: https URL yes, a space or another scheme no');
    $cli->next = ['output' => "sudo: x\n" . '{"ts":1,"host":{"values":{"cpu":3}},"apps":[],"events":[]}', 'exit_code' => 0, 'success' => true];
    $o = $met->overview();
    ok(end($cli->ran) === 'metrics overview --json' && $o['host']['values']['cpu'] === 3, 'overview: decoded past a sudo line');
    $cli->next = ['output' => "\033[0;31m[ERROR]\033[0m sqlite3 is not installed — apt-get install -y sqlite3", 'exit_code' => 1, 'success' => false];
    ok(str_contains((string) thrown(fn () => $met->status()), 'sqlite3 is not installed'), 'the CLI\'s [ERROR] line is the message');
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiMetricsService(new FakeCli, new FakeVersion('5.10.0')))->overview()), 'needs Cipi 5.11.0+'), 'an older core is refused');
    $cli->next = ['output' => '{"range":2592000,"apps":[{"app":"shop","uptime":99.9}]}', 'exit_code' => 0, 'success' => true];
    $met->uptime(null, '30d');
    ok(end($cli->ran) === "metrics uptime --range='30d' --json", 'uptime: all apps');
    $met->uptime('shop', null);
    ok(end($cli->ran) === "metrics uptime 'shop' --json", 'uptime: one app');
    $cli->next = ['output' => '{"from":1,"to":2,"events":[]}', 'exit_code' => 0, 'success' => true];
    $met->events(['app' => 'shop', 'source' => 'deploy', 'range' => '7d', 'limit' => 20]);
    ok(end($cli->ran) === "metrics events --app='shop' --source='deploy' --range='7d' --limit=20 --json", 'events: filters');
    foreach (['metrics overview --json', "metrics show --app='shop' --json", 'metrics uptime --json', 'metrics events --json', 'metrics status --json', 'metrics retention --raw=7d', 'metrics probe on', 'metrics enable', 'metrics disable', "metrics heartbeat set 'https://x' --every=1", 'metrics heartbeat off', "basicauth agent 'shop' status"] as $c) {
        ok((new FakeCli)->commandIsPermitted($c), "allow-list: {$c}");
    }
    ok(! (new FakeCli)->commandIsPermitted('metrics openmetrics on --allow=1.2.3.4') && ! (new FakeCli)->commandIsPermitted('metrics reset --yes') && ! (new FakeCli)->commandIsPermitted('metrics prune'), 'not on the allow-list: openmetrics, reset, prune');
    ok(CipiApi\Services\CipiMetricsService::parseAgentStatus('cipi/agent routes (/cipi/): public — the agent checks its own tokens') === ['mode' => 'public', 'public' => true, 'prefix' => '/cipi/'], 'basicauth agent: public, with its prefix');
    ok(CipiApi\Services\CipiMetricsService::parseAgentStatus('cipi/agent routes: behind basic auth (cipi basicauth agent shop public to change)')['mode'] === 'protected', 'basicauth agent: protected');
    ok(CipiApi\Services\CipiMetricsService::parseAgentStatus('cipi/agent routes: nothing left out (basic auth off, or no cipi/agent in the live release)') === ['mode' => 'public', 'public' => false, 'prefix' => null], 'basicauth agent: nothing left out');
    $cfg = file_get_contents(dirname($src, 2) . '/config/cipi.php');
    ok(str_contains($cfg, "'metrics-view'") && str_contains($cfg, "'metrics-manage'"), 'abilities metrics-view and metrics-manage');
    $routes = file_get_contents(dirname($src, 2) . '/routes/api.php');
    ok(str_contains($routes, "Route::get('/metrics', [MetricsController::class, 'overview'])->middleware('ability:metrics-view')") && str_contains($routes, "Route::put('/metrics/settings', [MetricsController::class, 'updateSettings'])->middleware('ability:metrics-manage')") && str_contains($routes, "Route::get('/apps/{name}/metrics', [MetricsController::class, 'appSeries'])->middleware('ability:metrics-view')"), 'metrics routes and their abilities');
    $spec = json_decode(file_get_contents(dirname($src, 2) . '/public/api-docs/openapi.json'), true);
    ok(isset($spec['paths']['/metrics']['get'], $spec['paths']['/metrics/series']['get'], $spec['paths']['/metrics/uptime']['get'], $spec['paths']['/metrics/events']['get'], $spec['paths']['/metrics/settings']['put'], $spec['paths']['/metrics/heartbeat']['put'], $spec['paths']['/apps/{name}/metrics']['get'], $spec['paths']['/apps/{name}/basicauth/agent']['put']), 'OpenAPI documents the metrics and basicauth agent routes');
    $mcp = file_get_contents(dirname($src, 2) . '/src/Mcp/Servers/CipiServer.php');
    ok(substr_count($mcp, 'Metrics') >= 12, 'six MCP tools registered');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
