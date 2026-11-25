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
    foreach (['CipiCliService', 'CipiVersionService', 'CipiValidationService', 'CipiDbUploadService', 'CipiBackupCliService', 'CipiDeployHistoryCliService', 'CipiServerStatusService', 'CipiAppJitService', 'CipiFrankenPhpService', 'CipiOutputParser', 'CipiMetricsService', 'CipiNotificationsService', 'CipiIpWhitelistService', 'CipiFirewallService', 'CipiJobAbilities', 'CipiPackageCliService'] as $c) {
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

    echo "--- Notifications (1.42.0, Cipi 5.12.0)\n";
    class ScriptCli extends FakeCli {
        public array $by = [];
        public function run(string $command): array {
            if (! $this->commandIsPermitted($command)) { return ['output' => "Command not allowed: {$command}", 'exit_code' => 1, 'success' => false]; }
            $this->ran[] = $command;
            foreach ($this->by as $prefix => $r) { if (str_starts_with($command, $prefix)) { return $r; } }
            return ['output' => '✓ done', 'exit_code' => 0, 'success' => true];
        }
    }
    $st = ['output' => "sudo: x\n" . json_encode(['channels' => [['id' => 'smtp', 'type' => 'smtp', 'enabled' => true, 'host' => 'smtp.test'], ['id' => 'ops', 'type' => 'slack', 'enabled' => true, 'url' => 'https://hooks.slack.com/services/***'], ['id' => 'oncall', 'type' => 'telegram', 'enabled' => false, 'token_set' => true, 'chat_id' => '-1']], 'triggers' => ['enabled' => 107, 'total' => 109], 'types' => CipiApi\Services\CipiNotificationsService::TYPES]), 'exit_code' => 0, 'success' => true];
    $cli = new ScriptCli; $cli->by = ['notification status --json' => $st];
    $nt = new CipiApi\Services\CipiNotificationsService($cli, new FakeVersion('5.12.0'));
    ok(count($nt->status()['channels']) === 3 && $nt->channel('oncall')['type'] === 'telegram' && $nt->channel('ghost') === null, 'status decoded past a sudo line; channel() finds one by id');
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiNotificationsService(new ScriptCli, new FakeVersion('5.11.2')))->status()), 'needs Cipi 5.12.0+'), 'an older core is refused');
    $E = fn (array $i, ?array $c = null) => $nt->channelError($i, $c);
    ok($E(['type' => 'slack', 'id' => 'ops', 'url' => 'https://hooks.slack.com/services/x']) === null, 'add slack: valid');
    ok(str_starts_with((string) $E(['type' => 'slack', 'url' => 'https://x']), 'id is required'), 'add: id required (not for smtp)');
    ok($E(['type' => 'smtp', 'host' => 'h', 'user' => 'u', 'password' => 'p', 'from' => 'a@x.it', 'to' => 'b@x.it']) === null, 'add smtp: no id needed');
    ok(str_starts_with((string) $E(['type' => 'telegram', 'id' => 't', 'token' => '1:a']), 'Required for telegram: chat_id'), 'add: the missing fields are named');
    ok(str_starts_with((string) $E(['type' => 'slack', 'id' => 'a', 'url' => 'https://x', 'token' => 'x']), 'Not a field of a slack channel: token'), 'a field of another type is refused');
    ok(str_contains((string) $E(['type' => 'slack', 'id' => 'a', 'url' => 'http://x']), 'https://'), 'slack: http:// refused');
    ok($E(['type' => 'webhook', 'id' => 'a', 'url' => 'http://intra.test/in']) === null, 'webhook: http:// allowed');
    ok(str_contains((string) $E(['type' => 'resend', 'id' => 'm', 'api_key' => 're_x', 'from' => 'a@x.it', 'to' => 'nope']), 'email'), 'resend: a recipient that is not an address');
    ok($E(['chat_id' => '-2'], ['id' => 'oncall', 'type' => 'telegram']) === null, 'update: only the fields that change, no secret needed');
    ok(str_contains((string) $E(['type' => 'slack'], ['id' => 'oncall', 'type' => 'telegram']), 'remove it and add'), 'update: the type cannot change');
    ok(str_contains((string) $E(['type' => 'slack', 'id' => 'smtp', 'url' => 'https://x']), "'smtp' is the id"), "the id smtp is the SMTP channel's");
    ok($nt->addCommand(['type' => 'telegram', 'id' => 'oncall', 'token' => "1:AB'C", 'chat_id' => '-100']) === "notification add telegram oncall --token='1:AB'\\''C' --chat-id='-100'", 'add: flags with dashes, values quoted');
    ok($nt->addCommand(['type' => 'smtp', 'host' => 'smtp.test', 'port' => 587, 'user' => 'u', 'password' => 'p w', 'from' => 'a@x.it', 'to' => 'b@x.it', 'tls' => false, 'enabled' => true]) === "notification add smtp --host='smtp.test' --port=587 --user='u' --password='p w' --from='a@x.it' --to='b@x.it' --tls=off --enabled=on --no-test", 'add smtp: no id, tls on/off, never the CLI\'s own test');
    ok($nt->updateCommand('mail', 'resend', ['to' => 'a@x.it,b@x.it', 'api_key' => '']) === "notification update mail --to='a@x.it,b@x.it'", 'update: an empty secret is left out (kept)');
    ok($nt->updateCommand('siem', 'webhook', ['clear_secret' => true]) === 'notification update siem --clear-secret', 'update webhook: --clear-secret');
    $cli->ran = [];
    $r = $nt->add(['type' => 'slack', 'id' => 'ops2', 'url' => 'https://hooks.slack.com/services/x', 'enabled' => false]);
    ok($cli->ran[0] === "notification add slack ops2 --url='https://hooks.slack.com/services/x'" && $cli->ran[1] === 'notification disable ops2' && $r['test'] === null, 'add disabled: add, then disable');
    $cli->ran = [];
    $cli->by = ['notification test' => ['output' => "→ Sending test alert to 'ops'...\n\033[0;31m[ERROR]\033[0m Test to 'ops' failed — the service refused the request or did not answer in time", 'exit_code' => 1, 'success' => false], 'notification status --json' => $st];
    $r = $nt->update('ops', 'slack', ['url' => 'https://hooks.slack.com/services/y', 'enabled' => true, 'test' => true]);
    ok($cli->ran[0] === "notification update ops --url='https://hooks.slack.com/services/y'" && $cli->ran[1] === 'notification enable ops' && in_array('notification test ops', $cli->ran, true), 'update: change, enable, test');
    ok($r['test']['ok'] === false && str_starts_with($r['test']['message'], "Test to 'ops' failed"), 'a refused test: ok false, the CLI\'s reason');
    $cli->by['notification test'] = ['output' => "→ Sending...\n✓ Test sent to 'ops' — check the channel", 'exit_code' => 0, 'success' => true];
    ok($nt->test('ops') === ['ok' => true, 'message' => "Test sent to 'ops' — check the channel"], 'a test sent: ok true');
    $cli->ran = []; $nt->remove('smtp');
    ok($cli->ran === ['notification remove smtp --force'], 'remove smtp: --force (no prompt)');
    ok($nt->triggersCommands(['triggers' => ['deploy_success' => false, 'ssh_login' => false, 'deploy_fail' => true], 'off' => ['sudo']]) === ['notification triggers set --on=deploy_fail --off=sudo,deploy_success,ssh_login'], 'triggers: one set, on and off');
    ok($nt->triggersCommands(['all' => false, 'on' => ['deploy_fail']]) === ['notification triggers disable-all', 'notification triggers set --on=deploy_fail'], 'triggers: all off, then the exceptions');
    ok($nt->triggersError([]) !== null && str_contains((string) $nt->triggersError(['triggers' => ['x; rm' => true]]), 'Not a trigger'), 'triggers: nothing to change, a bad name');
    foreach (['notification status --json', 'notification triggers --json', 'notification triggers set --on=a', 'notification triggers reset', 'notification add slack x --url=y', 'notification update x --url=y', 'notification remove x', 'notification test all', 'notification enable x', 'notification disable x'] as $c) {
        ok((new FakeCli)->commandIsPermitted($c), "allow-list: {$c}");
    }
    $cfg = file_get_contents(dirname($src, 2) . '/config/cipi.php');
    ok(str_contains($cfg, "'notifications-view'") && str_contains($cfg, "'notifications-manage'"), 'abilities notifications-view and notifications-manage');
    $routes = file_get_contents(dirname($src, 2) . '/routes/api.php');
    ok(str_contains($routes, "Route::get('/notifications', [NotificationController::class, 'show'])->middleware('ability:notifications-view,smtp-view')") && str_contains($routes, "Route::put('/notifications/channels/{id}', [NotificationController::class, 'update'])->middleware('ability:notifications-manage,smtp-manage')") && str_contains($routes, "Route::put('/notifications/triggers', [NotificationController::class, 'updateTriggers'])->middleware('ability:notifications-manage,smtp-manage')"), 'notification routes: new abilities, or the smtp ones');
    $spec = json_decode(file_get_contents(dirname($src, 2) . '/public/api-docs/openapi.json'), true);
    ok(version_compare($spec['info']['version'], '1.42.0', '>=') && isset($spec['paths']['/notifications']['get'], $spec['paths']['/notifications/channels']['post'], $spec['paths']['/notifications/channels/{id}']['put'], $spec['paths']['/notifications/channels/{id}/test']['post'], $spec['paths']['/notifications/triggers']['put']), 'OpenAPI >= 1.42.0: the notification routes');
    $mcp = file_get_contents(dirname($src, 2) . '/src/Mcp/Servers/CipiServer.php');
    ok(substr_count($mcp, 'Notification') >= 10, 'five notification MCP tools registered');

    echo "--- 1.43.0 (Cipi 5.13.0)\n";
    foreach (['CipiBasicAuthCliService', 'CipiDatabaseEnginesCliService', 'CipiAppEnvService'] as $c) {
        require_once $src . $c . '.php';
    }
    $parser = new CipiApi\Services\CipiOutputParser;
    // A CLI that answers each command from a script, in order.
    $scripted = new class extends FakeCli {
        public array $answers = [];
        public function run(string $command): array {
            if (! $this->commandIsPermitted($command)) { return ['output' => "Command not allowed: {$command}", 'exit_code' => 1, 'success' => false]; }
            $this->ran[] = $command;
            return array_shift($this->answers) ?? ['output' => '', 'exit_code' => 0, 'success' => true];
        }
    };
    $ansi = "\e[1mBasic auth — shop\e[0m\n━━━━━━━━━━━━━━━━\n  Status     \e[2mdisabled\e[0m\n";
    $c = clone $scripted; $c->answers = [['output' => '{"app":"shop","enabled":true,"users":["admin","ops"],"agent":{"mode":"public","public_prefix":"/cipi/"}}', 'exit_code' => 0, 'success' => true]];
    $st = (new CipiApi\Services\CipiBasicAuthCliService($c, $parser))->status('shop');
    ok($c->ran === ["basicauth status 'shop' --json"] && $st['enabled'] === true && $st['users'] === ['admin', 'ops'] && $st['agent'] === ['mode' => 'public', 'public_prefix' => '/cipi/'], 'basic auth status: --json, users and agent');
    $c = clone $scripted; $c->answers = [['output' => $ansi, 'exit_code' => 1, 'success' => false]];
    $st = null; $err = thrown(function () use ($c, $parser, &$st) { $st = (new CipiApi\Services\CipiBasicAuthCliService($c, $parser))->status('shop'); });
    ok($err === null && $st['enabled'] === false && count($c->ran) === 1, 'Cipi 5.11–5.12 (status printed, then exit 1): read as disabled, no raw output thrown at the panel');
    $c = clone $scripted; $c->answers = [['output' => "Unknown flag", 'exit_code' => 1, 'success' => false], ['output' => "  Status     enabled\n  Users      admin\n", 'exit_code' => 0, 'success' => true]];
    $st = (new CipiApi\Services\CipiBasicAuthCliService($c, $parser))->status('shop');
    ok(count($c->ran) === 2 && $st['enabled'] === true && $st['users'] === ['admin'], 'a core that refuses --json is asked again in text');
    $c = clone $scripted; $c->answers = [['output' => "\e[31m✗\e[0m App 'x' not found", 'exit_code' => 1, 'success' => false], ['output' => "\e[31m✗\e[0m App 'x' not found", 'exit_code' => 1, 'success' => false]];
    $err = (string) thrown(fn () => (new CipiApi\Services\CipiBasicAuthCliService($c, $parser))->status('x'));
    ok(str_contains($err, "App 'x' not found") && ! str_contains($err, "\e["), 'an error comes back without colour codes: ' . $err);
    $c = clone $scripted; $c->answers = [['output' => "\e[32m✓\e[0m User 'admin' added\n\e[32m✓\e[0m Basic auth enabled\n", 'exit_code' => 1, 'success' => false]];
    $en = (new CipiApi\Services\CipiBasicAuthCliService($c, $parser))->enable('shop', 'admin');
    ok($en['enabled'] === true && str_contains($en['notice'] ?? '', 'set the password again'), 'enable on Cipi 5.11–5.12 (enabled, then exit 1): enabled, with a notice');

    $c = clone $scripted; $c->answers = [['output' => '{"default":"mariadb","engines":[{"engine":"mariadb","status":"installed","port":3306,"default":true,"server":"mysql","label":"MySQL"},{"engine":"pgsql","status":"not_installed","port":5432,"default":false,"server":"pgsql","label":"PostgreSQL"}]}', 'exit_code' => 0, 'success' => true]];
    $en = (new CipiApi\Services\CipiDatabaseEnginesCliService($c, $parser, new FakeVersion('5.13.0')))->list();
    ok($c->ran === ['db engines --json'] && $en['engines'][0]['server'] === 'mysql' && $en['engines'][0]['label'] === 'MySQL' && $en['engines'][0]['engine'] === 'mariadb', 'engines: --json on 5.13.0, server mysql on the engine mariadb');
    $table = "\n\e[1mDatabase engines\e[0m\n  \e[1mENGINE       STATUS         PORT     DEFAULT\e[0m\n  mariadb      installed      3306     *\n  pgsql        not_installed  5432     \n\n  Install:  cipi db install pgsql\n  Default:  cipi db default <engine>\n  Port 3306 runs MySQL (engine id: mariadb). Back to MariaDB: cipi db switch mariadb\n";
    $c = clone $scripted; $c->answers = [['output' => $table, 'exit_code' => 0, 'success' => true]];
    $en = (new CipiApi\Services\CipiDatabaseEnginesCliService($c, $parser, new FakeVersion('5.12.0')))->list();
    ok($c->ran === ['db engines'] && count($en['engines']) === 2 && $en['engines'][0]['server'] === 'mysql' && $en['engines'][1]['label'] === 'PostgreSQL' && $en['default'] === 'mariadb', 'engines: the table on an older core (no --json the sudoers would refuse); the MySQL line read');
    $c = clone $scripted; $c->answers = [['output' => str_replace('Port 3306 runs MySQL', 'MySQL instead of MariaDB', $table), 'exit_code' => 0, 'success' => true]];
    $en = (new CipiApi\Services\CipiDatabaseEnginesCliService($c, $parser))->list();
    ok($en['engines'][0]['server'] === 'mariadb' && $en['engines'][0]['label'] === 'MariaDB', 'engines: MariaDB when the table does not say MySQL');

    $vtmp = $GLOBALS['T'] . '/apps.json'; file_put_contents($vtmp, json_encode(['shop' => ['php' => '8.4'], 'site' => ['custom' => true]]));
    $val = new class($vtmp) extends CipiApi\Services\CipiValidationService {
        public function __construct(public string $f) {}
        public function appExists(string $name): bool { return array_key_exists($name, json_decode(file_get_contents($this->f), true)); }
        public function isCustomApp(string $name): bool { return (json_decode(file_get_contents($this->f), true)[$name]['custom'] ?? false) === true; }
    };
    $c = clone $scripted; $c->answers = [['output' => '{"DB_HOST":"127.0.0.1","APP_NAME":"Shop","NEW":"x"}', 'exit_code' => 0, 'success' => true]];
    $env = new CipiApi\Services\CipiAppEnvService($c, $val, new FakeVersion('5.13.0'));
    $r = $env->update('shop', ['NEW' => 'x'], [], ['DB_HOST', 'APP_NAME', 'NEW']);
    ok($c->ran === ["app env 'shop' --set='NEW=x' --order='DB_HOST,APP_NAME,NEW' --json"] && array_keys($r['vars']) === ['DB_HOST', 'APP_NAME', 'NEW'], 'env: set, then --order, vars back in the file order');
    ok(str_contains((string) thrown(fn () => $env->update('shop', [], [], ['A', 'A'])), 'twice'), 'env order: a key twice is refused before the CLI');
    ok(str_contains((string) thrown(fn () => $env->update('shop', [], [], ['A; rm'])), 'Invalid env key'), 'env order: an invalid key is refused');
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiAppEnvService(clone $scripted, $val, new FakeVersion('5.12.0')))->update('shop', [], [], ['A'])), 'needs Cipi 5.13.0'), 'env order on an older core: refused, not sent');
    ok(str_contains((string) thrown(fn () => $env->update('shop')), 'set, unset or order'), 'env: nothing to do is refused');
    $c = clone $scripted; $c->answers = [['output' => "\e[31m✗\e[0m Order not applied: the order leaves out APP_KEY", 'exit_code' => 1, 'success' => false]];
    $err = (string) thrown(fn () => (new CipiApi\Services\CipiAppEnvService($c, $val, new FakeVersion('5.13.0')))->update('shop', [], [], ['APP_NAME']));
    ok(str_starts_with($err, 'InvalidArgumentException: ✗ Order not applied: the order leaves out APP_KEY'), "the core's refusal of an order: 422, without colours");

    $bk13 = new CipiApi\Services\CipiBackupCliService(new FakeCli, new FakeVersion('5.13.0'));
    $f = $bk13->profileFlags(['exclude_paths' => ['storage/app/uploads', 'shop:*.mp4', 'my files/a[1]']], true);
    ok($f['errors'] === [] && $f['flags'] === ["--exclude-paths='storage/app/uploads,shop:*.mp4,my files/a[1]'"], 'exclude_paths: one quoted flag: ' . implode(' ', $f['flags']));
    ok($bk13->profileFlags(['exclude_paths' => []], true)['flags'] === ['--exclude-paths='], 'exclude_paths: an empty list on edit clears it');
    foreach (['/var/www', 'shared', '*', 'shop:htdocs', "a'; rm -rf /", "x\ny"] as $bad) {
        ok(isset($bk13->profileFlags(['exclude_paths' => [$bad]], true)['errors']['exclude_paths']), 'exclude_paths refused: ' . json_encode($bad));
    }
    ok(str_contains((string) ($bk->profileFlags(['exclude_paths' => ['storage/app/uploads']], true)['errors']['exclude_paths'] ?? ''), 'needs Cipi 5.13.0'), 'exclude_paths on an older core: refused (it would ignore the flag)');
    ok($bk->profileFlags(['exclude_paths' => []], true) === ['flags' => [], 'errors' => []] || ($bk->profileFlags(['exclude_paths' => []], true)['flags'] ?? null) === [], 'exclude_paths: an empty list on an older core passes no flag');

    ok($parser->parse('app-deploy-unlock', "\e[32m✓\e[0m Deploy unlocked — run: cipi deploy shop\n\e[32m✓\e[0m Automatic deploys resumed — commit 0ff6512a9c1e deploys again on the next push or webhook\n") === ['unlocked' => true, 'resumed' => true, 'resumed_commit' => '0ff6512a9c1e'], 'unlock: lock removed and commit resumed');
    ok($parser->parse('app-deploy-unlock', "✓ Automatic deploys resumed\n") === ['unlocked' => false, 'resumed' => true], 'unlock: only a paused commit');
    $spec = json_decode(file_get_contents(dirname($src, 2) . '/public/api-docs/openapi.json'), true);
    $sc = $spec['components']['schemas'];
    ok(version_compare($spec['info']['version'], '1.43.0', '>=') && isset($sc['AppEnvUpdateRequest']['properties']['order'], $sc['BackupProfileRequest']['properties']['exclude_paths'], $sc['BackupProfileCreateRequest']['properties']['exclude_paths'], $sc['DbEnginesResponse']['properties']['data']['properties']['engines']['items']['properties']['server'], $sc['DeployStatusResponse']['properties']['data']['properties']['paused_commit'], $sc['BasicAuthStatusResponse']['properties']['data']['properties']['agent'], $sc['JobResultAppDeployUnlock']['properties']['resumed']), 'OpenAPI 1.43.0: order, exclude_paths, server, paused_commit, agent, resumed');
    ok(str_contains(file_get_contents(dirname($src, 2) . '/src/Mcp/Tools/AppEnvUpdateTool.php'), "'order' =>"), 'MCP AppEnvUpdate: order');
    ok(str_contains(file_get_contents(dirname($src, 2) . '/src/Http/Controllers/BackupController.php'), "'exclude_paths',"), 'backup controller passes exclude_paths');

    echo "--- firewall (Cipi 5.14.0)\n";
    $F = 'CipiApi\Services\CipiFirewallService';
    foreach (['8080' => '8080', '0080' => '80', '8000:8100' => '8000:8100', 22 => '22', ' 443 ' => '443'] as $in => $want) {
        ok($F::normalizePort($in) === $want, "port " . json_encode($in) . " → {$want}");
    }
    foreach (['0', '65536', '8000:7000', '8000:8000', '22;id', '80,443', 'ssh', '-1', '', '1:70000'] as $bad) {
        ok($F::normalizePort($bad) === null, 'port refused: ' . json_encode($bad));
    }
    ok($F::normalizePort(['22']) === null, 'port refused: an array');
    $cli = new FakeCli;
    $fw = new $F($cli, new FakeVersion('5.14.0'), new CipiApi\Services\CipiIpWhitelistService($cli));
    foreach (['1.2.3.4', '10.0.0.0/8', '2001:db8::/48', '::1'] as $a) { ok($fw->isAddress($a), "address {$a}"); }
    foreach (['*', '1.2.3.4;id', '999.1.1.1', '', '1.2.3.4/33', 'example.com', '-1.2.3.4', "1.2.3.4\n5.6.7.8"] as $a) { ok(! $fw->isAddress($a), 'not an address: ' . json_encode($a)); }
    ok($F::tooWide('0.0.0.0/0') && $F::tooWide('10.0.0.0/7') && ! $F::tooWide('10.0.0.0/8') && $F::tooWide('2001::/15') && ! $F::tooWide('2001:db8::/16') && ! $F::tooWide('1.2.3.4'), 'too wide: IPv4 below /8, IPv6 below /16');
    ok($fw->takesIn('203.0.113.0/24', $fw->clientIp('::ffff:203.0.113.9')) && $fw->takesIn('203.0.113.9', '203.0.113.9') && ! $fw->takesIn('203.0.114.0/24', '203.0.113.9') && ! $fw->takesIn('203.0.113.0/24', ''), 'the caller: in a range, itself, IPv4-mapped IPv6 read as IPv4; nobody when unknown');
    $cli->next = ['output' => "\e[32m✓\e[0m Allowed 8080/tcp", 'exit_code' => 0, 'success' => true];
    ok($fw->allow('8080', null) === 'Allowed 8080/tcp', 'allow: the CLI line, without colours or ✓');
    $fw->allow('5432', '10.0.0.0/8', 'udp');
    $fw->remove('8080', null);
    $fw->remove('3000', null, 'any');
    $fw->remove('22', '198.51.100.0/24', 'tcp', 'deny');
    $fw->block('192.0.2.66'); $fw->unblock('2001:db8:bad::/48');
    $fw->attempts(5); $fw->attempts(null);
    $cli->next = ['output' => 'IP 11.2.3.45 unbanned', 'exit_code' => 0, 'success' => true]; $fw->unban('11.2.3.45');
    $fw->crowdsecAllow('203.0.113.0/24');
    $cli->next = ['output' => 'Removed 203.0.113.0/24', 'exit_code' => 0, 'success' => true]; $fw->crowdsecUnallow('203.0.113.0/24');
    ok($cli->ran === [
        "firewall allow '8080'", "firewall allow '5432' --from='10.0.0.0/8' --proto='udp'",
        "firewall remove '8080'", "firewall remove '3000' --proto='any'", "firewall remove '22' --from='198.51.100.0/24' --deny",
        "firewall block '192.0.2.66'", "firewall unblock '2001:db8:bad::/48'", 'firewall attempts 5', 'firewall attempts default',
        "ban unban '11.2.3.45'", "crowdsec allow '203.0.113.0/24'", "crowdsec unallow '203.0.113.0/24'",
    ], 'every change is a whitelisted command, values quoted: ' . json_encode($cli->ran));
    $cli->next = ['output' => "\e[0;31m[ERROR]\e[0m Port 443 is how this server is reached (SSH, HTTP, HTTPS): it is not closed from here\n  To close it to the world, Zero Trust keeps it working: cipi zt lock http|ssh", 'exit_code' => 1, 'success' => false];
    $err = (string) thrown(fn () => $fw->remove('443', null));
    ok(str_starts_with($err, 'InvalidArgumentException: Port 443 is how this server is reached') && ! str_contains($err, '[ERROR]') && ! str_contains($err, "\e"), "the CLI's refusal: 422 with its words, no colours, no [ERROR]");
    $cli->next = ['output' => "\e[1;33m[WARN]\e[0m 1.2.3.4 is not currently banned", 'exit_code' => 0, 'success' => true];
    ok(str_contains((string) thrown(fn () => $fw->unban('1.2.3.4')), '1.2.3.4 is not currently banned'), 'unban of an address not banned: 422');
    $cli->next = ['output' => "[WARN] 9.9.9.9 is not on the extra allowlist", 'exit_code' => 0, 'success' => true];
    ok(str_contains((string) thrown(fn () => $fw->crowdsecUnallow('9.9.9.9')), 'not on the CrowdSec allowlist'), 'unallow of an address not there: 422');
    $old = new $F(new FakeCli, new FakeVersion('5.13.1'), new CipiApi\Services\CipiIpWhitelistService(new FakeCli));
    ok(str_starts_with((string) thrown(fn () => $old->allow('8080', null)), 'RuntimeException: The firewall from the panel needs Cipi 5.14.0+ — this server runs Cipi 5.13.1'), 'an older core: refused before anything runs (503)');
    ok(str_starts_with((string) thrown(fn () => $old->status()), 'RuntimeException'), '…the status too');
    ok($F::crowdsecEnableCommand('203.0.113.9') === "crowdsec enable --allow='203.0.113.9'" && $F::crowdsecEnableCommand('') === 'crowdsec enable', 'crowdsec enable: the caller allowlisted');
    $perm = new CipiApi\Services\CipiCliService;
    ok($perm->commandIsPermitted($F::crowdsecEnableCommand('203.0.113.9')) && $perm->commandIsPermitted('crowdsec disable --force') && $perm->commandIsPermitted('firewall status --json'), 'crowdsec enable, disable --force and the status are whitelisted');
    foreach (['firewall deny 22', 'crowdsec disable', 'crowdsec rescue token', 'crowdsec refresh', 'firewall status', 'firewall list'] as $c) {
        ok(! $perm->commandIsPermitted($c), "not whitelisted: {$c}");
    }
    ok(CipiApi\Services\CipiJobAbilities::forType('crowdsec-enable') === 'firewall-manage' && CipiApi\Services\CipiJobAbilities::forType('crowdsec-disable') === 'firewall-manage', 'CrowdSec jobs are read back with firewall-manage');
    $raw = '{"ufw":{"installed":true,"active":true,"default_incoming":"deny","default_outgoing":"allow","rules":[{"action":"allow","log":false,"proto":"tcp","port":"22","to":"any","from":"any","app":"","direction":"in","interface":"","comment":"","family":"both","managed_by":"","kind":"port","protected":true,"removable":false},{"action":"deny","port":"any","proto":"any","from":"192.0.2.66","family":"v4","kind":"block","removable":true},"junk"]},"protected_ports":[22,80,443,"x"],"zero_trust":{"lock_http":true},"fail2ban":{"installed":true,"running":true,"attempts":3,"attempts_default":3,"attempts_custom":null,"findtime":3600,"bantime":86400,"jails":[{"jail":"sshd","banned":["11.2.3.45"],"count":1,"total":9,"failed":2},{"x":1}]},"crowdsec":{"installed":false,"decisions":[{"ip":""}],"enable_blockers":["Only 300 MB of RAM free"]}}';
    $cli2 = new FakeCli; $cli2->next = ['output' => $raw, 'exit_code' => 0, 'success' => true];
    $st = (new $F($cli2, new FakeVersion('5.14.0'), new CipiApi\Services\CipiIpWhitelistService($cli2)))->status();
    ok($cli2->ran === ['firewall status --json'], 'status: one command');
    ok(count($st['ufw']['rules']) === 2 && $st['ufw']['rules'][0]['protected'] === true && $st['ufw']['rules'][1]['kind'] === 'block' && $st['ufw']['rules'][1]['to'] === 'any' && $st['ufw']['rules'][1]['managed_by'] === '', 'status: rules typed, every key present, junk dropped');
    ok($st['protected_ports'] === [22, 80, 443] && $st['zero_trust'] === ['lock_http' => true, 'lock_ssh' => false], 'status: protected ports and Zero Trust locks');
    ok($st['fail2ban']['attempts'] === 3 && $st['fail2ban']['attempts_custom'] === null && count($st['fail2ban']['jails']) === 1 && $st['fail2ban']['jails'][0]['banned'] === ['11.2.3.45'], 'status: fail2ban');
    ok($st['crowdsec']['installed'] === false && $st['crowdsec']['decisions'] === [] && $st['crowdsec']['enable_blockers'] === ['Only 300 MB of RAM free'] && $st['crowdsec']['allowlist'] === [], 'status: CrowdSec, a decision without an address dropped');
    $cli2->next = ['output' => 'not json', 'exit_code' => 0, 'success' => true];
    ok(str_contains((string) thrown(fn () => (new $F($cli2, new FakeVersion('5.14.0'), new CipiApi\Services\CipiIpWhitelistService($cli2)))->status()), 'invalid JSON'), 'status: invalid JSON is an error, never an empty firewall');
    $routes = file_get_contents(dirname($src, 2) . '/routes/api.php');
    // 1.45.0 adds the three real-ip routes, firewall-manage too.
    ok(substr_count($routes, "FirewallController::class") === 14 && str_contains($routes, "Route::get('/firewall', [FirewallController::class, 'status'])->middleware('ability:firewall-view')") && substr_count($routes, "->middleware('ability:firewall-manage')") === 13, 'routes: GET with firewall-view, the thirteen changes with firewall-manage');
    $cfg = file_get_contents(dirname($src, 2) . '/config/cipi.php');
    ok(str_contains($cfg, "'firewall-view' =>") && str_contains($cfg, "'firewall-manage' =>"), 'abilities firewall-view and firewall-manage');
    ok(isset($spec['paths']['/firewall']['get'], $spec['paths']['/firewall/rules']['post'], $spec['paths']['/firewall/rules']['delete'], $spec['paths']['/firewall/blocks']['delete'], $spec['paths']['/firewall/attempts']['put'], $spec['paths']['/firewall/bans']['delete'], $spec['paths']['/firewall/crowdsec/enable']['post'], $spec['paths']['/firewall/crowdsec/allowlist']['delete'], $sc['FirewallStatus'], $sc['FirewallRule']) && version_compare($spec['info']['version'], '1.44.0', '>='), 'OpenAPI 1.44.0+: the firewall routes and schemas');
    $mcp = file_get_contents(dirname($src, 2) . '/src/Mcp/Servers/CipiServer.php');
    preg_match('/defaultPaginationLength = (\d+)/', $mcp, $pg);
    ok(substr_count($mcp, '::class,') <= (int) ($pg[1] ?? 0) && str_contains($mcp, 'FirewallStatusTool::class'), 'MCP: FirewallStatus, and every tool fits in one tools/list page (' . substr_count($mcp, '::class,') . ' ≤ ' . ($pg[1] ?? '?') . ')');

    echo "--- optional packages (Cipi 5.14.0)\n";
    $P = 'CipiApi\Services\CipiPackageCliService';
    foreach (['browsershot', 'fonts-cjk', 'image-optimizers', 'avif', 'tesseract'] as $id) { ok($P::validId($id), "id {$id}"); }
    foreach (['', 'Browsershot', 'ffmpeg;id', '-ffmpeg', 'a b', '../x', str_repeat('a', 41), "ffmpeg\n"] as $bad) { ok(! $P::validId($bad), 'not an id: ' . json_encode($bad)); }
    ok($P::installCommand('browsershot') === "package install 'browsershot' --yes" && $P::removeCommand('pdftk') === "package remove 'pdftk' --yes", 'commands: the id quoted, --yes, never --force or --autoremove');
    $perm = new CipiApi\Services\CipiCliService;
    ok($perm->commandIsPermitted($P::installCommand('browsershot')) && $perm->commandIsPermitted($P::removeCommand('pdftk')) && $perm->commandIsPermitted('package list --json'), 'install, remove and list are whitelisted');
    ok(! $perm->commandIsPermitted('package purge ffmpeg') && ! $perm->commandIsPermitted('packages install x'), 'nothing else of package is');
    ok(CipiApi\Services\CipiJobAbilities::forType('package-install') === 'packages-manage' && CipiApi\Services\CipiJobAbilities::forType('package-remove') === 'packages-manage', 'package jobs are read back with packages-manage');
    $cli = new FakeCli;
    $cli->next = ['output' => '{"packages":[{"id":"browsershot","packages":["libnss3","libasound2t64"],"description":"Libraries","kind":"libraries","binaries":[],"missing":["libnss3"],"installed":false,"partial":false},{"id":"pdftk","packages":["pdftk-java"],"description":"PDF","kind":"tools","binaries":["pdftk"],"missing":[],"installed":true,"partial":false},{"x":1}]}', 'exit_code' => 0, 'success' => true];
    $pk = new $P($cli, new FakeVersion('5.14.0'));
    $list = $pk->list();
    ok(count($list) === 2 && $list[0]['kind'] === 'libraries' && $list[0]['missing'] === ['libnss3'] && $list[0]['binaries'] === [] && $list[1]['binaries'] === ['pdftk'] && $list[1]['installed'] === true, 'list: kind, binaries, missing typed; junk dropped');
    ok($pk->find('pdftk')['installed'] === true && $pk->find('chromium') === null, 'find: an entry of the catalog, null for anything else');
    $cli->next = ['output' => '{"packages":[{"id":"ffmpeg","packages":["ffmpeg"],"description":"Video","installed":true,"partial":false}]}', 'exit_code' => 0, 'success' => true];
    $l = $pk->list();
    ok($l[0]['kind'] === 'tools' && $l[0]['binaries'] === [] && $l[0]['missing'] === [] && $l[0]['source'] === 'ubuntu' && $l[0]['version'] === null && $l[0]['outdated'] === false, 'list from an older core: tools from Ubuntu, empty binaries and missing');
    $cli->next = ['output' => '{"packages":[{"id":"sec-nuclei","packages":[],"description":"nuclei","kind":"tools","binaries":["nuclei"],"missing":[],"installed":false,"partial":false,"source":"upstream","version":"3.11.1","installed_version":"3.10.0","outdated":true},{"id":"sec-zap","packages":["openjdk-21-jre-headless"],"description":"ZAP","kind":"tools","binaries":["zap.sh"],"missing":["zap.sh"],"installed":false,"partial":false,"source":"upstream","version":"2.17.0","installed_version":null,"outdated":false}]}', 'exit_code' => 0, 'success' => true];
    $l = $pk->list();
    ok($l[0]['source'] === 'upstream' && $l[0]['version'] === '3.11.1' && $l[0]['installed_version'] === '3.10.0' && $l[0]['outdated'] === true && $l[1]['installed_version'] === null && $l[1]['outdated'] === false, 'list: upstream entries with the pinned version, the installed one, outdated');
    ok(thrown(fn () => $pk->requireManage()) === null, 'Cipi 5.14.0 may install from the panel');
    ok(str_starts_with((string) thrown(fn () => (new $P(new FakeCli, new FakeVersion('5.13.1')))->requireManage()), 'RuntimeException: Installing and removing packages from the panel needs Cipi 5.14.0+ — this server runs Cipi 5.13.1'), 'an older core (its sudoers list only): 503');
    $parser = new CipiApi\Services\CipiOutputParser;
    $r = $parser->parse('package-install', "\e[0;36m→\e[0m Installing libreoffice-writer-nogui...\n\n\e[0;32m✓\e[0m libreoffice installed — soffice available\n");
    ok($r === ['package' => 'libreoffice', 'installed' => true, 'already' => false, 'binaries' => ['soffice'], 'missing' => []], 'parse install: the entry and its binaries');
    ok($parser->parse('package-install', "✓ browsershot installed\n")['binaries'] === [] && $parser->parse('package-install', "✓ ffmpeg is already installed\n")['already'] === true, 'parse install: libraries, already there');
    $r = $parser->parse('package-install', "→ Downloading nuclei_3.11.1_linux_amd64.zip...\n[INFO] SHA-256 matches the pin\n\n✓ sec-nuclei 3.11.1 installed — nuclei available\n");
    ok($r === ['package' => 'sec-nuclei', 'installed' => true, 'already' => false, 'binaries' => ['nuclei'], 'missing' => [], 'version' => '3.11.1'], 'parse install: an upstream entry and its version');
    ok($parser->parse('package-install', "✓ sec-zap 2.17.0 is already installed\n") === ['package' => 'sec-zap', 'version' => '2.17.0', 'installed' => true, 'already' => true], 'parse install: upstream, already there');
    ok(($parser->parse('package-install', "[ERROR] Checksum mismatch for nuclei_3.11.1_linux_amd64.zip — refused\n", false)['error'] ?? '') === 'Checksum mismatch for nuclei_3.11.1_linux_amd64.zip — refused', 'parse install refused on a checksum: the CLI\'s words');
    $r = $parser->parse('package-remove', "→ Purging what only sec-zap needed: openjdk-21-jre-headless java-common...\n✓ sec-zap removed\n");
    ok($r === ['package' => 'sec-zap', 'removed' => true, 'apt' => ['openjdk-21-jre-headless', 'java-common']], 'parse remove: an upstream entry and the apt packages only it needed');
    $r = $parser->parse('package-remove', "→ Purging pdftk-java default-jre-headless...\n✓ pdftk removed\n");
    ok($r === ['package' => 'pdftk', 'removed' => true, 'apt' => ['pdftk-java', 'default-jre-headless']], 'parse remove: what apt purged');
    ok($parser->parse('package-remove', "[INFO] browsershot: other packages still use these libraries — nothing to remove\n")['still_needed'] === true, 'parse remove: libraries still needed');
    ok(($parser->parse('package-remove', "[ERROR] Removing ffmpeg would also remove: reports\n", false)['error'] ?? '') === 'Removing ffmpeg would also remove: reports', "parse remove refused: the CLI's words");
    $routes = file_get_contents(dirname($src, 2) . '/routes/api.php');
    ok(str_contains($routes, "Route::post('/packages/{package}', [PackageController::class, 'install'])->middleware('ability:packages-manage')") && str_contains($routes, "Route::delete('/packages/{package}', [PackageController::class, 'remove'])->middleware('ability:packages-manage')"), 'routes: POST and DELETE /packages/{package} with packages-manage');
    ok(str_contains(file_get_contents(dirname($src, 2) . '/config/cipi.php'), "'packages-manage' =>"), 'ability packages-manage');
    $ctl = file_get_contents(dirname($src, 2) . '/src/Http/Controllers/PackageController.php');
    ok(str_contains($ctl, 'validId($package)') && str_contains($ctl, '->find($package)') && str_contains($ctl, '->requireManage()'), 'controller: the id checked, found in the catalog, the core new enough — before any job');
    ok(str_contains($ctl, "! \$entry['installed'] && ! \$entry['partial'] && ! \$entry['outdated']"), 'controller: an outdated upstream entry may be removed (and installed: it updates)');
    ok(isset($spec['paths']['/packages/{package}']['post'], $spec['paths']['/packages/{package}']['delete'], $sc['JobResultPackageInstall'], $sc['JobResultPackageRemove'], $sc['PackagesResponse']['properties']['data']['items']['properties']['kind']), 'OpenAPI: the package routes, kind, the job results');

    echo "--- nginx directives, live job output, IPv6 (Cipi 5.14.0)\n";
    $root = $argv[1] ?? dirname(__DIR__);
    foreach (['CipiNginxCliService', 'NginxConfigRefusedException'] as $c) {
        require_once $root . "/src/Services/{$c}.php";
    }
    $ncli = new FakeCli;
    $ng = new CipiApi\Services\CipiNginxCliService($ncli, new CipiApi\Services\CipiOutputParser);
    $ncli->next = ['output' => '{"app":"shop","config":"expires 1d;\n","bytes":12,"max_bytes":32768,"file":"/etc/nginx/cipi-custom/shop.conf","active":true,"suspended":false}', 'exit_code' => 0, 'success' => true];
    $shown = $ng->show('shop');
    ok($ncli->ran === ["nginx show 'shop' --json"] && $shown === ['app' => 'shop', 'config' => "expires 1d;\n", 'bytes' => 12, 'max_bytes' => 32768, 'active' => true, 'suspended' => false], 'nginx show: the text, its size, whether it is live — not the path on the server');
    $ncli->ran = [];
    $ng->update('shop', "expires 7d;\r\nlocation /x { deny all; }\r\n");
    ok($ncli->ran[0] === "nginx set 'shop' --content-b64='" . base64_encode("expires 7d;\nlocation /x { deny all; }\n") . "'" && $ncli->ran[1] === "nginx show 'shop' --json", 'nginx update: the text goes as base64, line ends as LF, then it is read back');
    $ncli->ran = [];
    $ng->update('shop', "  \n\t");
    ok($ncli->ran[0] === "nginx reset 'shop'", 'nginx update: an empty text removes the directives');
    $ncli->next = ['output' => "\e[0;31m[ERROR]\e[0m Not accepted from the API — the whole nginx language is for 'cipi nginx edit shop' at the server\n  line 1: `listen` is not allowed from the API — the ports of an app are Cipi's\n  line 2: root: a path under /home/shop/, without variables or `..`", 'exit_code' => 1, 'success' => false];
    $refused = null;
    try { $ng->update('shop', "listen 81;\nroot /etc;"); } catch (CipiApi\Services\NginxConfigRefusedException $e) { $refused = $e; }
    ok($refused !== null && count($refused->reasons()) === 3 && str_starts_with($refused->reasons()[0], 'Not accepted from the API') && $refused->reasons()[1] === "line 1: `listen` is not allowed from the API — the ports of an app are Cipi's", 'nginx update: what the CLI refuses comes back as reasons, one per line, without colours');
    $ncli->next = ['output' => "[ERROR] nginx refused the directives — nothing changed\n  line 2: unknown directive \"boom\"", 'exit_code' => 1, 'success' => false];
    $refused = null;
    try { $ng->update('shop', "expires 1d;\nboom;"); } catch (CipiApi\Services\NginxConfigRefusedException $e) { $refused = $e; }
    ok($refused !== null && $refused->reasons() === ['nginx refused the directives — nothing changed', 'line 2: unknown directive "boom"'], 'nginx update: nginx\'s own verdict too');
    $ncli->next = ['output' => '[ERROR] Use: cipi nginx default-server on|off|status | security | upgrade [--yes]', 'exit_code' => 1, 'success' => false];
    ok(str_contains((string) thrown(fn () => $ng->show('shop')), 'Cipi 5.14.0+ is required') && str_starts_with((string) thrown(fn () => $ng->update('shop', 'expires 1d;')), 'RuntimeException'), 'nginx: a CLI before 5.14.0 is told apart from a refused text');
    $ncli->next = ['output' => '[ERROR] nginx configuration is already invalid — fix it first (nginx -t)', 'exit_code' => 1, 'success' => false];
    ok(str_starts_with((string) thrown(fn () => $ng->update('shop', 'expires 1d;')), 'RuntimeException: [ERROR] nginx configuration is already invalid'), 'nginx update: a server problem is not a refused text (503, not 422)');
    $perm = new CipiApi\Services\CipiCliService;
    ok($perm->commandIsPermitted("nginx show 'shop' --json") && $perm->commandIsPermitted("nginx set 'shop' --content-b64='eA=='") && $perm->commandIsPermitted("nginx reset 'shop'")
        && ! $perm->commandIsPermitted("nginx edit 'shop'") && ! $perm->commandIsPermitted('nginx upgrade --yes') && ! $perm->commandIsPermitted('nginx default-server off') && ! $perm->commandIsPermitted('nginx security'), 'allowed commands: nginx show, set, reset — not edit, upgrade, default-server');
    $routes = (string) file_get_contents($root . '/routes/api.php');
    ok(str_contains($routes, "Route::get('/apps/{name}/nginx', [NginxController::class, 'show'])->middleware('ability:nginx-view');")
        && str_contains($routes, "Route::put('/apps/{name}/nginx', [NginxController::class, 'update'])->middleware('ability:nginx-manage');")
        && str_contains($routes, "Route::delete('/apps/{name}/nginx', [NginxController::class, 'destroy'])->middleware('ability:nginx-manage');"), 'routes: GET with nginx-view, PUT and DELETE with nginx-manage');
    $abilities = (string) file_get_contents($root . '/config/cipi.php');
    ok(str_contains($abilities, "'nginx-view' =>") && str_contains($abilities, "'nginx-manage' =>"), 'abilities: nginx-view, nginx-manage');
    $nctl = (string) file_get_contents($root . '/src/Http/Controllers/NginxController.php');
    ok(str_contains($nctl, "'reasons' => \$e->reasons()], 422") && str_contains($nctl, "'config' => 'present|nullable|string|max:' . CipiNginxCliService::MAX_BYTES"), 'controller: a refused text is a 422 with the reasons');
    ok(isset($spec['paths']['/apps/{name}/nginx']['get'], $spec['paths']['/apps/{name}/nginx']['put'], $spec['paths']['/apps/{name}/nginx']['delete'], $sc['NginxDirectives']), 'OpenAPI: /apps/{name}/nginx');

    // A job's output while it runs.
    $stream = new class extends CipiApi\Services\CipiCliService {
        public string $line = '';
        protected function shellLine(string $command): string { return $this->line . ' 2>&1'; }
    };
    $stream->line = "sh -c 'printf \"one  \\n\"; sleep 0.3; printf \"two\\n\" >&2; sleep 0.3; printf \"three\\t\\n\\n\"; exit 3'";
    $seenAt = []; $t0 = microtime(true);
    $streamed = $stream->runStreaming('status', function (string $soFar) use (&$seenAt, $t0) { $seenAt[] = [microtime(true) - $t0, $soFar]; });
    ok($streamed === $stream->run('status') && $streamed === ['output' => "one\ntwo\nthree\n", 'exit_code' => 3, 'success' => false], 'runStreaming: the result run() gives — output as exec() shapes it, the exit code');
    ok(count($seenAt) >= 2 && $seenAt[0][1] === "one  \n" && $seenAt[0][0] < 0.25 && str_ends_with(end($seenAt)[1], "three\t\n\n"), 'runStreaming: the output is handed over as it arrives, whole so far each time');
    ok($stream->runStreaming('rm -rf /', fn () => null)['output'] === 'Command not allowed: rm -rf /', 'runStreaming: a command not allowed is not run');
    $stream->line = "sh -c 'echo x'";
    ok($stream->runStreaming('status', function () { throw new RuntimeException('db down'); }) === ['output' => 'x', 'exit_code' => 0, 'success' => true], 'runStreaming: a failing listener does not stop the command');
    ok(CipiApi\Services\CipiCliService::asExec('') === '' && CipiApi\Services\CipiCliService::asExec("a \n\nb") === "a\n\nb", 'asExec: trailing whitespace of a line, the last newline');
    $jobSrc = (string) file_get_contents($root . '/src/Jobs/RunCipiCommand.php');
    $modelSrc = (string) file_get_contents($root . '/src/Models/CipiJob.php');
    $statusSrc = (string) file_get_contents($root . '/src/Services/CipiJobStatusService.php');
    ok(str_contains($jobSrc, '$cipi->runStreaming($this->command, function (string $soFar) use ($job, &$saved): void {') && str_contains($jobSrc, 'if ($now - $saved >= 1.0) {') && str_contains($jobSrc, "\$job->markCompleted(\$result['output'], \$result['exit_code']);"), 'job: progress saved once a second at most, the final output as before');
    ok(str_contains($modelSrc, "if (\$this->status !== 'running') {") && str_contains($modelSrc, 'substr($output, -self::PROGRESS_CHARS)') && (bool) preg_match('/recordProgress.*?catch \(\\\\Throwable\)/s', $modelSrc), 'job model: progress only while running, its last 50,000 characters, never an error');
    ok(str_contains($statusSrc, "\$job->status === 'running' && is_string(\$job->output) && \$job->output !== ''") && str_contains($statusSrc, "\$data['output'] = \$this->truncateOutput(CipiCliService::asExec(\$job->output));"), 'GET /jobs/{id}: a running job carries its output so far, no result yet');

    // The server's IPv6 address.
    $S = CipiApi\Services\CipiServerStatusService::class;
    $ipOut = "2: eth0    inet6 2a01:4f8:c0c:1234:aaaa:bbbb:cccc:dddd/64 scope global temporary dynamic \\       valid_lft 600sec\n2: eth0    inet6 2A01:4F8:C0C:1234::1/64 scope global \\       valid_lft forever preferred_lft forever\n3: docker0    inet6 fd00:dead:beef::1/48 scope global \\  valid_lft forever\n";
    ok($S::firstPublicIpv6($ipOut) === '2a01:4f8:c0c:1234::1', 'IPv6: the first global address — not a temporary one, lower case');
    ok($S::firstPublicIpv6('') === null && $S::firstPublicIpv6("3: docker0    inet6 fd00::1/48 scope global \n4: x inet6 2001:db8::5/64 scope global deprecated \n") === null && $S::firstPublicIpv6('2: eth0    inet6 2001:db8::7;id/64 scope global') === null, 'IPv6: none, only unique-local or deprecated ones, or not an address: null');
    ok(str_contains((string) file_get_contents($root . '/src/Services/CipiServerStatusService.php'), "'ipv6' => \$this->publicIpv6(),") && isset($spec['paths']) && str_contains((string) file_get_contents($root . '/public/api-docs/openapi.json'), '"ipv6": {'), 'status: system.ipv6, in the OpenAPI too');

    echo "--- 1.45.0 (Cipi 5.15.0): Cloudflare real IP, events per channel, MySQL's warning\n";
    $F = 'CipiApi\Services\CipiFirewallService';
    $st = $F::normalizeStatus(['real_ip' => ['enabled' => true, 'provider' => 'cloudflare', 'header' => 'CF-Connecting-IP', 'kept_by' => 'real-ip', 'ranges' => '22', 'refreshed_at' => '2026-11-25T03:23:00Z', 'nightly_refresh' => true, 'can_disable' => true, 'bans_active' => true]]);
    ok($st['real_ip'] === ['enabled' => true, 'provider' => 'cloudflare', 'header' => 'CF-Connecting-IP', 'kept_by' => 'real-ip', 'ranges' => 22, 'refreshed_at' => '2026-11-25T03:23:00Z', 'nightly_refresh' => true, 'can_disable' => true, 'bans_active' => true], 'status: real_ip, typed');
    ok($F::normalizeStatus([])['real_ip'] === null && $F::normalizeStatus(['real_ip' => ['kept_by' => 'evil', 'ranges' => 'x']])['real_ip']['kept_by'] === null, 'status: real_ip null on an older core; an unknown kept_by is null');
    $cli = new FakeCli;
    $fw = new $F($cli, new FakeVersion('5.15.0'), new CipiApi\Services\CipiIpWhitelistService($cli));
    $cli->next = ['output' => "\e[32m✓\e[0m nginx now takes the visitor's address from Cloudflare (22 ranges, refreshed every night)", 'exit_code' => 0, 'success' => true];
    ok(str_starts_with($fw->realIpEnable(), "nginx now takes the visitor's address"), 'real-ip on: the CLI line, without colours');
    $fw->realIpRefresh(); $fw->realIpDisable();
    ok($cli->ran === ['nginx real-ip cloudflare', 'nginx real-ip refresh', 'nginx real-ip off --yes'], 'real-ip: on, refresh, off --yes — whitelisted: ' . json_encode($cli->ran));
    $cli->next = ['output' => '[ERROR] nginx already has a real_ip configuration that Cipi did not write (set_real_ip_from in /etc/nginx)', 'exit_code' => 1, 'success' => false];
    ok(str_starts_with((string) thrown(fn () => $fw->realIpEnable()), 'InvalidArgumentException: nginx already has a real_ip configuration'), "real-ip: the CLI's refusal is a 422 with its words");
    $old = new $F(new FakeCli, new FakeVersion('5.14.0'), new CipiApi\Services\CipiIpWhitelistService(new FakeCli));
    ok(str_starts_with((string) thrown(fn () => $old->realIpEnable()), 'RuntimeException: Cloudflare real IP from the panel needs Cipi 5.15.0+'), 'real-ip on an older core: 503 before anything runs');
    $perm = new CipiApi\Services\CipiCliService;
    foreach (['nginx real-ip status --json', 'nginx real-ip cloudflare', 'nginx real-ip refresh', 'nginx real-ip off --yes'] as $c) { ok($perm->commandIsPermitted($c), "whitelisted: {$c}"); }
    foreach (['nginx real-ip off', 'nginx real-ip status', 'nginx upgrade', 'nginx default-server on'] as $c) { ok(! $perm->commandIsPermitted($c), "not whitelisted: {$c}"); }
    $ctl = (string) file_get_contents($root . '/src/Http/Controllers/FirewallController.php');
    ok(str_contains($ctl, "if (\$request->headers->has('CF-Connecting-IP')) {") && str_contains($ctl, '], 409);'), 'real-ip off: refused (409) when the request came through Cloudflare');
    ok(str_contains($routes, "Route::put('/firewall/real-ip', [FirewallController::class, 'realIpEnable'])->middleware('ability:firewall-manage')") && str_contains($routes, "Route::delete('/firewall/real-ip', [FirewallController::class, 'realIpDisable'])->middleware('ability:firewall-manage')") && str_contains($routes, "Route::post('/firewall/real-ip/refresh', [FirewallController::class, 'realIpRefresh'])->middleware('ability:firewall-manage')"), 'routes: real-ip with firewall-manage');
    $spec = json_decode((string) file_get_contents($root . '/public/api-docs/openapi.json'), true);
    ok(isset($spec['paths']['/firewall/real-ip']['put'], $spec['paths']['/firewall/real-ip']['delete'], $spec['paths']['/firewall/real-ip/refresh']['post'], $spec['components']['schemas']['FirewallStatus']['properties']['real_ip']) && $spec['info']['version'] === '1.45.0', 'OpenAPI 1.45.0: real-ip routes and real_ip');

    // Events per channel.
    $N = 'CipiApi\Services\CipiNotificationsService';
    $cli = new ScriptCli; $cli->by = ['notification status --json' => ['output' => json_encode(['channels' => [['id' => 'smtp', 'type' => 'smtp', 'enabled' => true, 'events' => null], ['id' => 'ops', 'type' => 'slack', 'enabled' => true, 'events' => ['security']]]]), 'exit_code' => 0, 'success' => true]];
    $nt = new $N($cli, new FakeVersion('5.15.0'));
    ok($N::eventsFlag(['security', 'deploy', 'security']) === '--events=security,deploy' && $N::eventsFlag(null) === '--events=all' && $N::eventsFlag([]) === '--events=all' && $N::eventsFlag(['deploy', 'all']) === '--events=all', 'events: one flag; null, [] or "all" — everything');
    ok($nt->channelError(['events' => ['security', 'backup_fail', 'basic-auth', 'urgent']], ['id' => 'ops', 'type' => 'slack']) === null, 'events: categories, triggers, urgent');
    ok(str_starts_with((string) $nt->channelError(['events' => ['Security;id']], ['id' => 'ops', 'type' => 'slack']), 'events:'), 'events: a selector out of shape is refused');
    ok($nt->addCommand(['type' => 'slack', 'id' => 'sec', 'url' => 'https://hooks.slack.com/x', 'events' => ['security']]) === "notification add slack sec --url='https://hooks.slack.com/x' --events=security", 'add: --events with the channel');
    ok($nt->updateCommand('smtp', 'smtp', ['events' => ['deploy']]) === 'notification update smtp --events=deploy', 'update smtp, events alone: no SMTP reconfigure, no test');
    ok($nt->updateCommand('smtp', 'smtp', ['to' => 'a@x.it', 'events' => null]) === "notification update smtp --to='a@x.it' --events=all --no-test", 'update smtp with a field: --no-test as before');
    $nt->update('ops', 'slack', ['events' => ['deploy', 'urgent']]);
    ok(in_array('notification update ops --events=deploy,urgent', $cli->ran, true), 'update: events alone reach the CLI');
    $older = new $N(new ScriptCli, new FakeVersion('5.14.0'));
    ok(str_contains((string) thrown(fn () => $older->update('ops', 'slack', ['events' => ['security']])), 'needs Cipi 5.15.0+'), 'events on an older core: refused, not silently ignored');
    ok(str_contains((string) thrown(fn () => $older->update('ops', 'slack', ['url' => 'https://hooks.slack.com/y'])), 'needs Cipi') === false, 'an older core still takes the other fields');

    // A client's stderr is not a database.
    require_once $src . 'CipiDatabaseListCliService.php';
    $D = 'CipiApi\Services\CipiDatabaseListCliService';
    $rows = [['name' => 'mysql: [Warning] Using a password on the command line interface can be insecure.', 'size_mb' => 0], ['name' => 'mozart'], ['name' => 'WARNING: database "shop" has a collation version mismatch'], ['name' => 'shop_tenant_1'], ['name' => 'mysql_archive'], 'junk'];
    ok(array_column($D::withoutClientNoise($rows), 'name') === ['mozart', 'shop_tenant_1', 'mysql_archive'], 'db list: the MySQL warning and psql notices are not databases; a name like mysql_archive is');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
