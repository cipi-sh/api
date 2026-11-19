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
    foreach (['CipiCliService', 'CipiVersionService', 'CipiValidationService', 'CipiDbUploadService', 'CipiBackupCliService', 'CipiDeployHistoryCliService', 'CipiServerStatusService'] as $c) {
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

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
