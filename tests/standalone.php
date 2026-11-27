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
// What RunCipiCommand takes from the queue, and the jobs table as far as it asks.
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Bus { trait Queueable {} }
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue {
    trait InteractsWithQueue {}
    trait SerializesModels {}
    class MaxAttemptsExceededException extends \RuntimeException {}
    class TimeoutExceededException extends MaxAttemptsExceededException {}
}
namespace CipiApi\Models {
    class CipiJob {
        public static array $rows = [];
        public function __construct(public string $status = 'running', public ?string $output = null, public string $type = 'app-deploy', public mixed $params = null, public ?int $exit_code = null) {}
        public static function find(string $id): ?self { return self::$rows[$id] ?? null; }
        public function markCompleted(string $output, int $exitCode): void { $this->status = $exitCode === 0 ? 'completed' : 'failed'; $this->output = $output; $this->exit_code = $exitCode; }
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
    ok($st['protected_ports'] === [22, 80, 443] && $st['zero_trust'] === ['lock_http' => true, 'lock_ssh' => false, 'by' => ['http' => [], 'ssh' => []]], 'status: protected ports and Zero Trust locks (an older core does not say who holds them)');
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
    ok(isset($spec['paths']['/firewall/real-ip']['put'], $spec['paths']['/firewall/real-ip']['delete'], $spec['paths']['/firewall/real-ip/refresh']['post'], $spec['components']['schemas']['FirewallStatus']['properties']['real_ip']) && version_compare($spec['info']['version'], '1.45.0', '>='), 'OpenAPI ≥ 1.45.0: real-ip routes and real_ip');

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

    echo "--- 1.46.0 (Cipi 5.16.0): Zero Trust details\n";
    require_once $src . 'CipiZtCliService.php';
    $Z = 'CipiApi\Services\CipiZtCliService';
    $zjson = ['enabled' => true, 'token' => true, 'tunnel' => ['id' => 't1', 'name' => 'cipi-mozart', 'running' => true, 'status' => 'healthy', 'connectors' => [['id' => 'c1', 'this_server' => true]]],
        'real_ip' => true, 'lock' => ['http' => true, 'http_mode' => 'dark', 'ssh' => false],
        'hostnames' => [['app' => 'shop', 'domains' => ['shop.example.com'], 'access' => ['enabled' => true, 'paths' => ['/admin'], 'allow' => ['@acme.io']]]],
        'ssh' => ['hostname' => 'ssh.example.com'], 'tcp' => [], 'service_tokens' => [['name' => 'panel', 'for' => 'api', 'client_id' => 'x.access']]];
    $cli = new ScriptCli; $cli->by = ['zt status --json' => ['output' => "sudo: unable to resolve host mozart\n" . json_encode($zjson, JSON_PRETTY_PRINT), 'exit_code' => 0, 'success' => true]];
    $zs = (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status();
    ok($zs['enabled'] === true && $zs['cloudflared'] === 'running' && $zs['tunnel'] === 'cipi-mozart' && $zs['lock_http'] === 'true (dark)' && $zs['lock_ssh'] === 'false' && $zs['ssh_hostname'] === 'ssh.example.com' && $zs['real_ip'] === 'CF-Connecting-IP', 'zt: the summary fields, read from the JSON of Cipi 5.16.0');
    ok(($zs['details']['hostnames'][0]['access']['paths'] ?? null) === ['/admin'] && $zs['raw'] === '' && in_array('zt status --json', $cli->ran, true), 'zt: details is the whole JSON; one call, zt status --json');
    $text = "\n  Cloudflare Zero Trust\n━━━━\n  cloudflared      \e[0;36mrunning\e[0m\n  Tunnel           cipi-old\n  real_ip          CF-Connecting-IP\n  lock http        false\n  lock ssh         false\n  SSH hostname     not configured\n";
    $cli = new ScriptCli; $cli->by = ['zt status --json' => ['output' => $text, 'exit_code' => 0, 'success' => true]];
    $zs = (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status();
    ok($zs['enabled'] === true && $zs['cloudflared'] === 'running' && $zs['tunnel'] === 'cipi-old' && $zs['details'] === null && str_contains($zs['raw'], 'Cloudflare Zero Trust'), 'zt on an older Cipi: the text it prints for the same command, as before');
    $cli = new ScriptCli; $cli->by = ['zt status --json' => ['output' => "\n  Cloudflare Zero Trust\n  not enabled  —  cipi zt enable\n", 'exit_code' => 0, 'success' => true]];
    $zs = (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status();
    ok($zs['enabled'] === false && $zs['tunnel'] === null, 'zt off on an older Cipi');
    $zs = $Z::fromJson(['enabled' => false, 'tunnel' => null]);
    ok($zs['enabled'] === false && $zs['cloudflared'] === null && $zs['details'] === ['enabled' => false, 'tunnel' => null], 'zt off in JSON');
    $spec = json_decode((string) file_get_contents($root . '/public/api-docs/openapi.json'), true);
    ok(version_compare($spec['info']['version'], '1.46.0', '>=') && isset($spec['components']['schemas']['ZtStatusResponse']['properties']['data']['properties']['details']), 'OpenAPI ≥ 1.46.0: details on GET /zt');

    echo "--- 1.47.0 (Cipi 5.17.0): page cache, Octane PHP, Valkey per app, notification settings\n";
    foreach (['CipiPageCacheService', 'PageCacheRefusedException', 'CipiValkeyCliService', 'ValkeyRefusedException'] as $c) { require_once $src . $c . '.php'; }
    $P = 'CipiApi\Services\CipiPageCacheService';
    $parser = new CipiApi\Services\CipiOutputParser;
    $cstat = ['app' => 'blog', 'supported' => true, 'kind' => 'fastcgi', 'enabled' => true, 'active' => true, 'ttl' => '10m', 'ttl_seconds' => 600, 'size' => '256m', 'clear_on_deploy' => true, 'files' => 12, 'bytes' => 4096, 'path' => '/var/cache/nginx/cipi/blog', 'suspended' => false, 'clear_command' => 'sudo /usr/local/bin/cipi-cache-clear blog'];
    $okJson = fn (array $j) => ['output' => "sudo: unable to resolve host x\n" . json_encode($j, JSON_PRETTY_PRINT), 'exit_code' => 0, 'success' => true];
    foreach (['cache --json', "cache 'blog' status --json", "cache 'blog' on --ttl='10m' --size='256m' --no-deploy-clear", "cache 'blog' off", "cache 'blog' clear", "frankenphp php 'shop' --json", "frankenphp php 'shop' '8.5' --yes", "frankenphp restart 'shop'", 'notification timezone --json', "notification timezone 'Europe/Rome' --json", "notification server-name 'production' --json", 'valkey list --json', "valkey isolate 'shop' --yes", 'valkey isolate --all --yes', "valkey share 'shop' --yes --no-copy", "valkey restart 'shop'", 'valkey flush-shared --yes'] as $c) {
        ok((new FakeCli)->commandIsPermitted($c), "allowed: cipi {$c}");
    }
    ok(! (new FakeCli)->commandIsPermitted('valkey flush-shared --force --yes') && ! (new FakeCli)->commandIsPermitted('valkey list') && ! (new FakeCli)->commandIsPermitted("frankenphp use 'shop' x"), 'not allowed: flush-shared --force, a valkey list that is not JSON, frankenphp use');

    $cli = new ScriptCli; $cli->by = ["cache 'blog' status --json" => $okJson($cstat), 'cache --json' => $okJson(['apps' => [$cstat, ['app' => 'spa', 'supported' => false] + $cstat]])];
    $pc = new $P($cli, new FakeVersion('5.17.0'), $parser);
    ok($pc->show('blog')['ttl_seconds'] === 600 && $pc->show('blog')['clear_command'] === 'sudo /usr/local/bin/cipi-cache-clear blog', 'page cache: the status, decoded past a sudo line');
    ok(count($pc->list()['apps']) === 2 && end($cli->ran) === 'cache --json', 'page cache: the list of every app');
    $pc->update('blog', ['enabled' => true, 'ttl' => '1h', 'size' => '2g', 'clear_on_deploy' => false]);
    ok(in_array("cache 'blog' on --ttl='1h' --size='2g' --no-deploy-clear", $cli->ran, true), 'page cache on: ttl, size and --no-deploy-clear, each quoted');
    $pc->update('blog', ['enabled' => true]);
    ok(in_array("cache 'blog' on", $cli->ran, true), 'page cache on with nothing else: what is stored stays');
    $pc->update('blog', ['enabled' => true, 'clear_on_deploy' => true]);
    ok(in_array("cache 'blog' on --deploy-clear", $cli->ran, true), 'clear_on_deploy true is --deploy-clear');
    $pc->update('blog', ['enabled' => false]);
    ok(in_array("cache 'blog' off", $cli->ran, true), 'page cache off');
    ok($pc->inputError(['enabled' => false, 'ttl' => '10m']) !== null && $pc->inputError(['enabled' => false]) === null && $pc->inputError(['enabled' => false, 'ttl' => null, 'size' => '']) === null, 'ttl, size and clear_on_deploy only with enabled: true (null and empty count as not given)');
    ok($pc->inputError(['enabled' => true, 'ttl' => '10 minutes']) !== null && $pc->inputError(['enabled' => true, 'ttl' => '31d']) !== null && $pc->inputError(['enabled' => true, 'ttl' => '30d']) === null && $pc->inputError(['enabled' => true, 'ttl' => '0s']) !== null, 'ttl: s, m, h, d — one second to thirty days');
    ok($pc->inputError(['enabled' => true, 'size' => '8m']) !== null && $pc->inputError(['enabled' => true, 'size' => '101g']) !== null && $pc->inputError(['enabled' => true, 'size' => '100g']) === null && $pc->inputError(['enabled' => true, 'size' => '1G']) !== null, 'size: 16m to 100g');
    ok($P::ttlSeconds('90s') === 90 && $P::ttlSeconds('2h') === 7200 && $P::ttlSeconds('1d') === 86400 && $P::ttlSeconds('x') === null && $P::sizeValid('16m') && ! $P::sizeValid('15m'), 'ttlSeconds and sizeValid, as the CLI counts them');
    $cli->by = ["cache 'blog' clear" => ['output' => "\e[0;32m✓\e[0m Page cache of 'blog' emptied — 128 page(s); the next visit to each page runs PHP and caches it again", 'exit_code' => 0, 'success' => true]] + $cli->by;
    ok($pc->clear('blog')['cleared'] === 128 && $pc->clear('blog')['enabled'] === true, 'clear: the pages deleted, and the status after it');
    $cli->by = ["cache 'blog' clear" => ['output' => "[INFO] The page cache of 'blog' is off — nothing to empty", 'exit_code' => 0, 'success' => true]] + $cli->by;
    ok($pc->clear('blog')['cleared'] === 0, 'clear with the cache off: nothing deleted, no error');
    $cli = new ScriptCli; $cli->by = ["cache 'blog' on" => ['output' => "\e[0;31m[ERROR]\e[0m nginx refused the configuration — nothing changed\n  [emerg] \"fastcgi_cache\" zone \"cipi_blog\" is unknown", 'exit_code' => 1, 'success' => false]];
    $pc = new $P($cli, new FakeVersion('5.17.0'), $parser);
    ok(str_starts_with((string) thrown(fn () => $pc->update('blog', ['enabled' => true])), 'CipiApi\Services\PageCacheRefusedException: nginx refused the configuration'), 'nginx refusing the cache is a refusal (422), with the CLI\'s words');
    $cli->by = ["cache 'spa' on" => ['output' => "[ERROR] 'spa' is a Node app: its pages come from its own server", 'exit_code' => 1, 'success' => false]];
    ok(str_starts_with((string) thrown(fn () => $pc->update('spa', ['enabled' => true])), 'CipiApi\Services\PageCacheRefusedException'), 'a Node app is a refusal too');
    // The paths an app keeps out of its cache.
    $cli = new ScriptCli; $cli->by = ["cache 'blog' status --json" => $okJson($cstat + ['exclude' => ['/cart*'], 'exclude_max' => 50])];
    $pc = new $P($cli, new FakeVersion('5.17.0'), $parser);
    foreach (["cache 'blog' exclude set '/cart*,/feed'", "cache 'blog' exclude remove --all", "cache 'blog' on --exclude='/cart*,/feed'", "cache 'blog' on --exclude=''"] as $c) {
        ok((new FakeCli)->commandIsPermitted($c), "allowed: cipi {$c}");
    }
    $pc->update('blog', ['enabled' => true, 'ttl' => '1h', 'exclude' => ['/cart*', 'feed', '/cart*', '/a**b']]);
    ok(in_array("cache 'blog' on --ttl='1h' --exclude='/cart*,/feed,/a*b'", $cli->ran, true), 'page cache on with exclude: the whole list in one quoted value, each path with its /, once');
    $pc->update('blog', ['enabled' => true, 'exclude' => []]);
    ok(in_array("cache 'blog' on --exclude=''", $cli->ran, true), 'an empty exclude with on: none of its own');
    $pc->update('blog', ['enabled' => true, 'exclude' => null]);
    ok(end($cli->ran) === "cache 'blog' status --json" && in_array("cache 'blog' on", $cli->ran, true), 'exclude null or left out: the list stays');
    ok($pc->exclude('blog', ['/my-account*', 'checkout'])['exclude'] === ['/cart*'] && in_array("cache 'blog' exclude set '/my-account*,/checkout'", $cli->ran, true), 'exclude alone: exclude set, and the status after it');
    $pc->exclude('blog', []);
    ok(in_array("cache 'blog' exclude remove --all", $cli->ran, true), 'an empty list alone: exclude remove --all');
    $X = fn (array $paths) => $P::excludeError($paths);
    ok($X(['/cart*', 'feed', '/shop/*/reviews', '/caff%C3%A8_1.0~x', '/']) === null, 'exclude: letters, digits, . _ ~ % - / and *');
    $badPaths = ['/a b', "/x';id", '/a;b', '/a"b', '/a$b', '/a{b}', '/a|b', '/(a)', '/a\\b', '/a?b=1', '/a,b', "/a\nb", '', '/' . str_repeat('a', 128)];
    ok(count(array_filter($badPaths, fn ($b) => $X([$b]) === null)) === 0 && $X([7]) !== null && $X([['/a']]) !== null, 'exclude: a space, a quote, ; $ { | ( \\ ? , a line break, an empty one, 129 characters, a number are refused');
    ok(str_contains((string) $X(['/*']), 'every page') && str_contains((string) $X(['*']), 'every page') && str_contains((string) $X(['/**']), 'every page'), 'exclude: /* is every page — the cache off, not a path');
    ok($X(array_map(fn ($i) => "/p{$i}", range(1, 50))) === null && str_contains((string) $X(array_map(fn ($i) => "/p{$i}", range(1, 51))), '50 paths at most'), 'exclude: 50 paths at most');
    $before = count($cli->ran);
    ok(str_starts_with((string) thrown(fn () => $pc->exclude('blog', ['/ok', '/a;rm -rf'])), 'CipiApi\Services\PageCacheRefusedException') && count($cli->ran) === $before, 'exclude: a path that is not one never reaches the CLI');
    ok($pc->inputError(['enabled' => false, 'exclude' => []]) !== null && $pc->inputError(['enabled' => false, 'exclude' => null]) === null && $pc->inputError(['enabled' => true, 'exclude' => ['/a;b']]) !== null && $pc->inputError(['enabled' => true, 'exclude' => ['/a*']]) === null, 'exclude in PUT /cache: with enabled true only, and checked');
    ok($P::excludeList(['a', '/a', '//b', '/c**d']) === ['/a', '/b', '/c*d'], 'excludeList: as the CLI keeps them');
    $cli->by = ["cache 'blog' exclude set" => ['output' => "[ERROR] nginx refused the configuration — nothing changed", 'exit_code' => 1, 'success' => false]];
    ok(str_starts_with((string) thrown(fn () => $pc->exclude('blog', ['/x'])), 'CipiApi\Services\PageCacheRefusedException: nginx refused'), 'exclude: nginx refusing is a 422 with its words');
    ok(str_contains((string) thrown(fn () => (new $P(new FakeCli, new FakeVersion('5.16.0'), $parser))->exclude('blog', ['/x'])), 'needs Cipi 5.17.0+'), 'exclude on an older core: refused before anything runs');
    $cli->by = ["cache 'blog' status" => ['output' => 'sudo: a password is required', 'exit_code' => 1, 'success' => false]];
    ok(str_contains((string) thrown(fn () => $pc->show('blog')), 'cipi api update'), 'sudoers older than the core: the message says what to run');
    ok(str_contains((string) thrown(fn () => (new $P(new FakeCli, new FakeVersion('5.16.0'), $parser))->show('blog')), 'needs Cipi 5.17.0+') && str_contains((string) thrown(fn () => (new $P(new FakeCli, new FakeVersion('5.16.0'), $parser))->update('blog', ['enabled' => true])), 'needs Cipi 5.17.0+'), 'page cache on an older core: refused before anything runs');

    $op = ['app' => 'shop', 'php' => '8.5', 'octane_php' => '8.6.0', 'build' => '1.14.0-php8.6.0-99137391', 'frankenphp' => '1.14.0', 'state' => 'mismatch', 'restart_pending' => false, 'available_php' => ['8.5', '8.6'], 'fix' => ['command' => 'cipi frankenphp php shop 8.5', 'why' => 'a build with PHP 8.5 is on this server']];
    $cli = new ScriptCli; $cli->by = ["frankenphp php 'shop' --json" => $okJson($op)];
    $fp = new CipiApi\Services\CipiFrankenPhpService($cli, new FakeVersion('5.17.0'));
    ok($fp->appPhp('shop')['state'] === 'mismatch' && $fp->appPhp('shop')['fix']['command'] === 'cipi frankenphp php shop 8.5' && $fp->appPhp('shop')['available_php'] === ['8.5', '8.6'], 'Octane: what answers requests, what runs the rest, and the fix for a mismatch');
    ok($fp->phpCommand('shop', '8.6') === "frankenphp php 'shop' '8.6' --yes" && $fp->restartCommand('shop') === "frankenphp restart 'shop'", 'Octane: the PHP by its number, and the restart');
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiFrankenPhpService(new FakeCli, new FakeVersion('5.16.0')))->phpCommand('shop', '8.5')), 'needs Cipi 5.17.0+') && (new CipiApi\Services\CipiFrankenPhpService(new FakeCli, new FakeVersion('5.16.0')))->upgradeCommand('shop') !== '', 'Octane PHP on an older core: refused — the upgrade of 5.10.0 still goes');
    $cli->by = ["frankenphp php 'shop' --json" => ['output' => "sudo: a password is required", 'exit_code' => 1, 'success' => false]];
    ok(str_contains((string) thrown(fn () => $fp->appPhp('shop')), 'cipi api update'), 'Octane: sudoers older than the core');
    $po = new ReflectionMethod($parser, 'parseOctanePhp'); $po->setAccessible(true);
    ok($po->invoke($parser, "✓ 'shop' is on PHP 8.5: Octane on 1.14.0-php8.5.4-aa11bb22, Composer, artisan, cron and workers on /usr/bin/php8.5") === ['app' => 'shop', 'php' => '8.5', 'build' => '1.14.0-php8.5.4-aa11bb22', 'changed' => true], 'job octane-php: the app, its PHP and its build');
    ok($po->invoke($parser, "[INFO] 'shop' is on PHP 8.5 already — Octane on 1.14.0-php8.5.4-aa11bb22, the rest on /usr/bin/php8.5")['changed'] === false, 'job octane-php: already there');
    ok(($po->invoke($parser, "[ERROR] Octane did not come up\n[ERROR] 'shop' was not moved: it stays on PHP 8.5, Octane on x")['changed'] ?? null) === false, 'job octane-php: left where it was');
    ok($parser->parse('octane-restart', '✓ shop-octane restarted and answering') === ['app' => 'shop', 'restarted' => true] && $parser->parse('octane-restart', '[INFO] shop-octane is stopped (supervisorctl start shop-octane) — left as it is')['stopped'] === true, 'job octane-restart');

    $V = 'CipiApi\Services\CipiValkeyCliService';
    $vl = ['shared_port' => 6379, 'apps' => [['app' => 'shop', 'uses' => 'own', 'port' => 6401, 'instance' => 'running', 'memory' => '2.31M', 'keys' => 412], ['app' => 'blog', 'uses' => 'shared', 'port' => null, 'instance' => null, 'memory' => null, 'keys' => null], ['app' => 'crm', 'uses' => 'external', 'port' => null, 'instance' => null, 'memory' => null, 'keys' => null]]];
    $cli = new ScriptCli; $cli->by = ['valkey list --json' => $okJson($vl)];
    $vk = new $V($cli, new FakeVersion('5.17.0'), $parser);
    $l = $vk->list();
    ok($l['shared'] === ['blog'] && $l['isolated'] === 1 && $l['shared_port'] === 6379 && count($l['apps']) === 3, 'valkey: the list, who is still on the shared instance, how many have their own');
    ok($vk->app('shop')['port'] === 6401 && $vk->app('ghost') === null, 'valkey: one app of the list');
    ok($vk->isolateCommand('blog') === "valkey isolate 'blog' --yes" && $vk->isolateCommand(null) === 'valkey isolate --all --yes' && $vk->isolateCommand('blog', false) === "valkey isolate 'blog' --yes --no-copy" && $vk->shareCommand('shop') === "valkey share 'shop' --yes", 'valkey: isolate one or --all, share — always --yes, --no-copy when asked');
    $newPass = 'S3cretNewSharedPassw0rdThatMustNotLeak0000';
    $cli->by = ['valkey flush-shared --yes' => ['output' => "✓ Shared Valkey emptied\n\n✓ New Valkey password: {$newPass}\n⚠ SAVE THIS PASSWORD — shown only once", 'exit_code' => 0, 'success' => true]] + $cli->by;
    $fl = $vk->flushShared();
    ok($fl['flushed'] === true && ! str_contains(json_encode($fl), $newPass), 'flush-shared: done, and the new password is nowhere in the answer');
    $cli->by = ['valkey flush-shared --yes' => ['output' => "[ERROR] Still in use by: blog crm — move them first (cipi valkey isolate --all), or --force", 'exit_code' => 1, 'success' => false]] + $cli->by;
    $e = (string) thrown(fn () => $vk->flushShared());
    ok(str_starts_with($e, 'CipiApi\Services\ValkeyRefusedException') && str_contains($e, 'blog crm') && ! str_contains($e, '--force'), 'flush-shared while apps use it: refused, the apps named, --force not suggested');
    $cli->by = ['valkey flush-shared --yes' => ['output' => "✓ Shared Valkey emptied\n✓ New Valkey password: {$newPass}\nsed: cannot write", 'exit_code' => 1, 'success' => false]] + $cli->by;
    ok(! str_contains((string) thrown(fn () => $vk->flushShared()), $newPass), 'flush-shared failing half way: the output is not in the error either');
    $cli->by = ["valkey restart 'blog'" => ['output' => '[ERROR] Usage: cipi valkey restart <app>  (an app with its own instance — cipi valkey list)', 'exit_code' => 1, 'success' => false]] + $cli->by;
    ok(str_starts_with((string) thrown(fn () => $vk->restart('blog')), 'CipiApi\Services\ValkeyRefusedException'), 'restart of an app without its own instance: refused');
    ok(str_contains((string) thrown(fn () => (new $V(new FakeCli, new FakeVersion('5.16.0'), $parser))->list()), 'needs Cipi 5.17.0+'), 'valkey from the API on an older core: refused');
    $mv = $parser->parse('valkey-isolate', "✓ shop: own Valkey on 127.0.0.1:6401 — 412 key(s) copied\n✓ blog: own Valkey on 127.0.0.1:6402\n✓ 2 app(s) moved to their own instance\n[WARN] Left on the shared instance: crm old — see above");
    ok($mv['moved'][0] === ['app' => 'shop', 'uses' => 'own', 'port' => 6401, 'keys_copied' => 412] && $mv['moved'][1]['keys_copied'] === null && $mv['left'] === ['crm', 'old'] && $mv['shared_can_be_flushed'] === false, 'job valkey-isolate: who moved, with port and keys, and who was left');
    $mv = $parser->parse('valkey-isolate', "✓ blog: own Valkey on 127.0.0.1:6402 — 3 key(s) copied\n[INFO] No app uses the shared instance any more. It still holds the old keys");
    ok($mv['shared_can_be_flushed'] === true, 'job valkey-isolate: nobody left on the shared instance');
    ok($parser->parse('valkey-share', '✓ shop: back on the shared Valkey (127.0.0.1:6379)')['moved'][0] === ['app' => 'shop', 'uses' => 'shared', 'port' => 6379, 'keys_copied' => null], 'job valkey-share');

    $ns = ['timezone' => null, 'server_timezone' => 'Etc/UTC', 'server_name' => null, 'hostname' => 'vmi12334', 'now' => '2026-11-26 10:00:00 UTC'];
    $cli = new ScriptCli; $cli->by = ['notification timezone --json' => $okJson($ns), "notification timezone 'Europe/Rome' --json" => $okJson(['timezone' => 'Europe/Rome', 'now' => '2026-11-26 11:00:00 CET'] + $ns), "notification server-name 'production' --json" => $okJson(['timezone' => 'Europe/Rome', 'server_name' => 'production'] + $ns), 'notification timezone server --json' => $okJson($ns), 'notification server-name --clear --json' => $okJson($ns)];
    $nt = new CipiApi\Services\CipiNotificationsService($cli, new FakeVersion('5.17.0'));
    ok($nt->settings()['hostname'] === 'vmi12334' && $nt->settings()['timezone'] === null, 'notification settings: read');
    $r = $nt->updateSettings(['timezone' => 'Europe/Rome', 'server_name' => 'production']);
    ok($r['server_name'] === 'production' && $r['timezone'] === 'Europe/Rome' && in_array("notification timezone 'Europe/Rome' --json", $cli->ran, true) && in_array("notification server-name 'production' --json", $cli->ran, true), 'notification settings: time zone and name set, the answer is the settings after both');
    $nt->updateSettings(['timezone' => null, 'server_name' => '']);
    ok(in_array('notification timezone server --json', $cli->ran, true) && in_array('notification server-name --clear --json', $cli->ran, true), 'null or "" puts the server\'s own time zone and the hostname back');
    $cli->by["notification server-name 'eu west 1' --json"] = $okJson(['server_name' => 'eu west 1'] + $ns);
    $n = count($cli->ran); $nt->updateSettings(['server_name' => 'eu west 1']);
    ok(count($cli->ran) === $n + 1 && end($cli->ran) === "notification server-name 'eu west 1' --json", 'only what is given is changed; a name with spaces is one argument');
    ok($nt->settingsError([]) !== null && $nt->settingsError(['timezone' => 'Europe/Rome; rm -rf /']) !== null && $nt->settingsError(['timezone' => '../../etc/passwd']) !== null && $nt->settingsError(['timezone' => 'America/Argentina/Buenos_Aires']) === null && $nt->settingsError(['timezone' => 'UTC']) === null && $nt->settingsError(['timezone' => null]) === null, 'time zone: an IANA name, nothing a shell or a path reads');
    ok($nt->settingsError(['server_name' => '--clear']) !== null && $nt->settingsError(['server_name' => str_repeat('a', 41)]) !== null && $nt->settingsError(['server_name' => "prod\nSubject: x"]) !== null && $nt->settingsError(['server_name' => 'prod $(id)']) !== null && $nt->settingsError(['server_name' => 'production']) === null && $nt->settingsError(['server_name' => 'EU West 1.a_b-c']) === null, 'server name: letters, digits, spaces, dots, dashes, underscores; not an option, not a header');
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiNotificationsService(new ScriptCli, new FakeVersion('5.16.0')))->settings()), 'needs Cipi 5.17.0+') && count((new CipiApi\Services\CipiNotificationsService(new ScriptCli, new FakeVersion('5.16.0')))->flags([])) === 0, 'notification settings on an older core: refused; the channels of 5.12.0 are not');

    $abil = (string) file_get_contents($src . 'CipiJobAbilities.php');
    ok(str_contains($abil, "'octane-php' => 'php-manage'") && str_contains($abil, "'octane-restart' => 'php-manage'") && str_contains($abil, "'valkey-isolate' => 'services-manage'") && str_contains($abil, "'valkey-share' => 'services-manage'"), 'the new job types have their ability');
    $routes = (string) file_get_contents($root . '/routes/api.php');
    foreach ([
        "Route::get('/cache', [PageCacheController::class, 'list'])->middleware('ability:nginx-view')",
        "Route::get('/apps/{name}/cache', [PageCacheController::class, 'show'])->middleware('ability:nginx-view')",
        "Route::put('/apps/{name}/cache', [PageCacheController::class, 'update'])->middleware('ability:nginx-manage')",
        "Route::put('/apps/{name}/cache/exclude', [PageCacheController::class, 'exclude'])->middleware('ability:nginx-manage')",
        "Route::post('/apps/{name}/cache/clear', [PageCacheController::class, 'clear'])->middleware('ability:nginx-manage')",
        "Route::get('/apps/{name}/octane', [FrankenPhpController::class, 'appPhp'])->middleware('ability:php-view')",
        "Route::put('/apps/{name}/octane/php', [FrankenPhpController::class, 'switchPhp'])->middleware('ability:php-manage')",
        "Route::post('/apps/{name}/octane/restart', [FrankenPhpController::class, 'restart'])->middleware('ability:php-manage')",
        "Route::get('/valkey', [ValkeyController::class, 'list'])->middleware('ability:services-view')",
        "Route::post('/valkey/isolate', [ValkeyController::class, 'isolate'])->middleware('ability:services-manage')",
        "Route::post('/valkey/share', [ValkeyController::class, 'share'])->middleware('ability:services-manage')",
        "Route::post('/valkey/flush-shared', [ValkeyController::class, 'flushShared'])->middleware('ability:services-manage')",
        "Route::post('/valkey/{name}/restart', [ValkeyController::class, 'restart'])->middleware('ability:services-manage')",
        "Route::get('/notifications/settings', [NotificationController::class, 'settings'])->middleware('ability:notifications-view,smtp-view')",
        "Route::put('/notifications/settings', [NotificationController::class, 'updateSettings'])->middleware('ability:notifications-manage,smtp-manage')",
    ] as $line) {
        ok(str_contains($routes, $line), 'route: ' . substr($line, 7, strpos($line, ',') - 7));
    }
    ok(strpos($routes, "'/valkey/flush-shared'") < strpos($routes, "'/valkey/{name}/restart'"), 'the fixed /valkey paths come before /valkey/{name}/restart');
    $spec = json_decode((string) file_get_contents($root . '/public/api-docs/openapi.json'), true);
    $sc = $spec['components']['schemas'];
    ok(version_compare($spec['info']['version'], '1.47.0', '>=') && isset($spec['paths']['/cache']['get'], $spec['paths']['/apps/{name}/cache']['get'], $spec['paths']['/apps/{name}/cache']['put'], $spec['paths']['/apps/{name}/cache/clear']['post'], $spec['paths']['/apps/{name}/cache/exclude']['put'], $sc['PageCacheExclude'], $sc['PageCache']['properties']['exclude'], $sc['PageCacheRequest']['properties']['exclude'], $spec['paths']['/apps/{name}/octane']['get'], $spec['paths']['/apps/{name}/octane/php']['put'], $spec['paths']['/apps/{name}/octane/restart']['post'], $spec['paths']['/valkey']['get'], $spec['paths']['/valkey/isolate']['post'], $spec['paths']['/valkey/share']['post'], $spec['paths']['/valkey/flush-shared']['post'], $spec['paths']['/valkey/{name}/restart']['post'], $spec['paths']['/notifications/settings']['get'], $spec['paths']['/notifications/settings']['put'], $sc['PageCache'], $sc['PageCacheRequest'], $sc['OctanePhp'], $sc['ValkeyList'], $sc['NotificationSettings'], $sc['JobResultOctanePhp'], $sc['JobResultValkeyMove']), 'OpenAPI 1.47.0: the new routes and schemas');
    $refs = []; array_walk_recursive($spec, function ($v, $k) use (&$refs) { if ($k === '$ref') { $refs[$v] = true; } });
    $missing = array_filter(array_keys($refs), function ($r) use ($spec) { $n = $spec; foreach (explode('/', substr($r, 2)) as $seg) { if (! isset($n[$seg])) { return true; } $n = $n[$seg]; } return false; });
    ok($missing === [], 'OpenAPI: every $ref points at something' . ($missing ? ': ' . implode(', ', $missing) : ''));
    $sud = '/home/claude/cipi/lib/cipi-api-sudoers.sh';
    if (is_file(getenv('CIPI_CORE_SUDOERS') ?: $sud)) {
        $sudoers = (string) file_get_contents(getenv('CIPI_CORE_SUDOERS') ?: $sud);
        ok(str_contains($sudoers, 'cipi cache *') && str_contains($sudoers, 'cipi valkey flush-shared --yes,') && str_contains($sudoers, 'cipi frankenphp php *') && str_contains($sudoers, 'cipi notification server-name *') && ! str_contains($sudoers, 'cipi valkey flush-shared *'), 'the core\'s sudoers carry what the API runs (flush-shared spelled out)');
    }

    echo "--- 1.48.0 (Cipi 5.18.0): the mesh network in GET /zt, who holds a firewall lock\n";
    $mesh = ['enabled' => true, 'provider' => 'tailscale', 'label' => 'Tailscale', 'connected' => true, 'state' => 'Running', 'ip' => '100.101.102.103',
        'fqdn' => 'web1.tail1234.ts.net', 'peers' => ['total' => 3, 'online' => 2], 'key_expiry' => null, 'lock' => ['ssh' => true, 'http' => false], 'warnings' => []];
    $zs = $Z::fromJson(['enabled' => false, 'tunnel' => null, 'mesh' => $mesh]);
    ok($zs['enabled'] === false && $zs['tunnel'] === null && $zs['lock_ssh'] === null && $zs['mesh'] === 'tailscale' && $zs['mesh_state'] === 'connected' && $zs['mesh_ip'] === '100.101.102.103',
        'zt: a mesh without Cloudflare — enabled stays Cloudflare\'s, mesh says tailscale, connected, its address');
    ok(($zs['details']['mesh']['lock'] ?? null) === ['ssh' => true, 'http' => false] && ($zs['details']['mesh']['peers']['online'] ?? null) === 2, 'zt: details.mesh is what the CLI says, whole');
    $zs = $Z::fromJson($zjson + ['mesh' => ['enabled' => true, 'provider' => 'netbird', 'connected' => false, 'state' => 'NeedsLogin', 'ip' => null]]);
    ok($zs['enabled'] === true && $zs['tunnel'] === 'cipi-mozart' && $zs['mesh'] === 'netbird' && $zs['mesh_state'] === 'disconnected' && $zs['mesh_ip'] === null, 'zt: Cloudflare and a mesh side by side; a mesh that is down has no address');
    $zs = $Z::fromJson(['enabled' => true, 'tunnel' => ['name' => 't'], 'mesh' => ['enabled' => false, 'provider' => null, 'active' => null]]);
    ok($zs['mesh'] === null && $zs['mesh_state'] === null && $zs['mesh_ip'] === null, 'zt: no mesh on');
    $zs = $Z::fromJson($zjson);
    ok(array_key_exists('mesh', $zs) && $zs['mesh'] === null && $zs['mesh_state'] === null, 'zt on Cipi 5.16/5.17 (no mesh in the JSON): the keys are there, null');
    $zs = $Z::fromJson(['enabled' => false, 'mesh' => ['enabled' => true, 'provider' => '', 'ip' => '']]);
    ok($zs['mesh'] === null && $zs['mesh_ip'] === null, 'zt: a mesh with no provider name is no mesh');
    $cli = new ScriptCli; $cli->by = ['zt status --json' => ['output' => $text, 'exit_code' => 0, 'success' => true]];
    $zs = (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status();
    ok(array_key_exists('mesh', $zs) && $zs['mesh'] === null && $zs['mesh_ip'] === null && $zs['tunnel'] === 'cipi-old', 'zt on a Cipi that prints text: mesh null, the rest as before');
    $raw2 = json_decode($raw, true); $raw2['zero_trust'] = ['lock_http' => true, 'lock_ssh' => true, 'by' => ['http' => ['cloudflare', 'tailscale'], 'ssh' => ['tailscale', ['x']]]];
    $cli2 = new FakeCli; $cli2->next = ['output' => json_encode($raw2), 'exit_code' => 0, 'success' => true];
    $st = (new $F($cli2, new FakeVersion('5.18.0'), new CipiApi\Services\CipiIpWhitelistService($cli2)))->status();
    ok($st['zero_trust'] === ['lock_http' => true, 'lock_ssh' => true, 'by' => ['http' => ['cloudflare', 'tailscale'], 'ssh' => ['tailscale']]], 'firewall status: who holds each lock, strings only');
    $perm = new CipiApi\Services\CipiCliService;
    // Cloudflare's side stays with the operator; of the mesh, only what the sudoers spell out.
    foreach (['zt enable --token=k', 'zt disable --yes', 'zt lock http --yes', 'zt lock ssh --yes', 'zt unlock http', 'zt hostname add shop', 'zt access set shop', 'zt reset',
        'zt tailscale disable', 'zt tailscale lock ssh', 'zt tailscale lock http', 'zt netbird ssh enable', 'zt tailscale up', 'zt headscale enable --auth-key=k'] as $c) {
        ok(! $perm->commandIsPermitted($c), "not whitelisted: {$c}");
    }
    ok($perm->commandIsPermitted('zt status --json'), 'zt status --json is what the API may run');
    $spec = json_decode((string) file_get_contents($root . '/public/api-docs/openapi.json'), true);
    $zp = $spec['components']['schemas']['ZtStatusResponse']['properties']['data']['properties'];
    ok(version_compare($spec['info']['version'], '1.48.0', '>=') && isset($zp['mesh'], $zp['mesh_state'], $zp['mesh_ip']) && str_contains($zp['details']['description'], '`mesh`')
        && isset($spec['components']['schemas']['FirewallStatus']['properties']['zero_trust']['properties']['by']['properties']['ssh']), 'OpenAPI ≥ 1.48.0: mesh on GET /zt, by on the firewall locks');

    echo "--- the mesh from the panel (Cipi 5.18.0, ability zt-manage)\n";
    require_once $src . 'ZtRefusedException.php';
    require_once $src . 'CipiZtMeshService.php';
    $M = 'CipiApi\Services\CipiZtMeshService';
    $R = 'CipiApi\Services\ZtRefusedException';
    foreach (['zt tailscale enable --auth-key=k', 'zt netbird enable --setup-key=k', 'zt tailscale disable --yes', "zt tailscale disable --yes --via='100.64.0.2'", 'zt tailscale lock ssh --yes', "zt netbird lock http --yes --via='203.0.113.9'",
        'zt tailscale unlock ssh', 'zt netbird unlock http', 'zt tailscale ssh enable', 'zt tailscale ssh disable', "zt status --json --via='203.0.113.9'"] as $c) {
        ok($perm->commandIsPermitted($c), "whitelisted: {$c}");
    }
    $sud = getenv('CIPI_CORE_SUDOERS') ?: '/home/claude/cipi/lib/cipi-api-sudoers.sh';
    if (is_file($sud)) {
        $sudoers = (string) file_get_contents($sud);
        $missing = [];
        foreach (CipiApi\Services\CipiCliService::ALLOWED_COMMANDS as $a) {
            if (! str_starts_with($a, 'zt tailscale') && ! str_starts_with($a, 'zt netbird')) { continue; }
            $line = '/usr/local/bin/cipi ' . (str_ends_with($a, ' ') ? $a . '*' : $a) . ',';
            if (! str_contains($sudoers, $line)) { $missing[] = $a; }
        }
        ok($missing === [], 'every mesh command of the whitelist is a line of the core\'s sudoers' . ($missing ? ': ' . implode(' | ', $missing) : ''));
        ok(str_contains($sudoers, 'cipi zt tailscale disable --yes *,') && str_contains($sudoers, 'cipi zt netbird lock http --yes *,') && ! str_contains($sudoers, 'cipi zt tailscale lock ssh --yes *') && ! str_contains($sudoers, 'cipi zt tailscale *') && ! str_contains($sudoers, 'cipi zt netbird ssh'),
            'sudoers: --via only where it is read (leave, lock http); lock ssh takes nothing more; no wildcard over the mesh; no NetBird SSH');
    }
    $J = fn (array $i) => $M::joinError($i);
    ok($J(['provider' => 'tailscale', 'key' => 'tskey-auth-kABC123CNTRL-xyz']) === null && $J(['provider' => 'netbird', 'key' => 'A1B2C3D4-E5F6-7890-ABCD-EF1234567890']) === null, 'join: an auth key, a setup key');
    ok($J(['provider' => 'tailscale']) === null && $J(['provider' => 'netbird', 'key' => '']) === null, 'join without a key: a client already connected is taken as it is');
    ok(str_contains((string) $J(['provider' => 'zerotier', 'key' => 'abcdefgh']), 'tailscale or netbird') && str_contains((string) $J([]), 'tailscale or netbird'), 'join: tailscale or netbird, nothing else');
    foreach (["tskey'; id", 'short', 'has space key', 'a"b"cdefgh', "tskey-\nx-abcdef", 'tskey$(id)x', 'tskey`id`xx'] as $bad) {
        ok(str_contains((string) $J(['provider' => 'tailscale', 'key' => $bad]), 'does not look like a key'), 'join: not a key ' . json_encode($bad));
    }
    ok(str_contains((string) $J(['provider' => 'tailscale', 'key' => 'tskey-abcdef', 'hostname' => 'web_1']), 'Not a hostname') && $J(['provider' => 'tailscale', 'key' => 'tskey-abcdef', 'hostname' => 'web-1']) === null, 'join: a hostname is letters, digits and -');
    ok(str_contains((string) $J(['provider' => 'tailscale', 'key' => 'tskey-abcdef', 'control_url' => 'ftp://x']), 'coordination server') && str_contains((string) $J(['provider' => 'netbird', 'key' => 'abcdefgh', 'control_url' => "https://nb.test'; id"]), 'coordination server')
        && $J(['provider' => 'tailscale', 'key' => 'tskey-abcdef', 'control_url' => 'https://headscale.example.com:8443']) === null && $J(['provider' => 'netbird', 'key' => 'abcdefgh', 'control_url' => 'https://netbird.example.com/']) === null, 'join: a control URL is http(s)://host[:port]');
    ok($M::tags('cipi, tag:prod ,cipi') === ['tag:cipi', 'tag:prod'] && $M::tags(['a', 'tag:b']) === ['tag:a', 'tag:b'] && $M::tags(null) === [] && $M::tags('') === [] && $M::tags('ok,no way') === null && $M::tags([['x']]) === null && $M::tags("a';id") === null, 'tags: a list or a,b — tag: added, doubles dropped; a bad one is refused');
    ok(str_contains((string) $J(['provider' => 'tailscale', 'key' => 'tskey-abcdef', 'tags' => 'no way']), 'Not a tag'), 'join: a tag that is not one');
    foreach (['tags' => 'cipi', 'ssh' => true, 'reauth' => true] as $f => $v) {
        ok(str_contains((string) $J(['provider' => 'netbird', 'key' => 'abcdefgh', $f => $v]), "'{$f}' is Tailscale's"), "join NetBird: {$f} is Tailscale's");
    }
    ok(str_contains((string) $J(['provider' => 'tailscale', 'reauth' => true]), 'needs a key') && $J(['provider' => 'tailscale', 'reauth' => true, 'key' => 'tskey-abcdef']) === null, 'join: reauth needs a key');
    ok($M::joinCommand(['provider' => 'tailscale', 'key' => 'tskey-auth-k1', 'hostname' => 'web1', 'control_url' => 'https://hs.example.com', 'tags' => ['cipi', 'tag:prod'], 'direct' => true, 'accept_dns' => true, 'accept_routes' => true, 'ssh' => true, 'reauth' => true])
        === "zt tailscale enable --auth-key='tskey-auth-k1' --hostname='web1' --login-server='https://hs.example.com' --tags='tag:cipi,tag:prod' --direct --accept-dns --accept-routes --ssh --reauth", 'join Tailscale: every flag, values quoted');
    ok($M::joinCommand(['provider' => 'netbird', 'key' => 'A1B2C3D4-E5F6', 'hostname' => 'web1', 'control_url' => 'https://nb.example.com', 'direct' => false, 'accept_dns' => '0', 'ssh' => true, 'tags' => 'x'])
        === "zt netbird enable --setup-key='A1B2C3D4-E5F6' --hostname='web1' --management-url='https://nb.example.com'", 'join NetBird: its own flag names; nothing of Tailscale\'s, nothing that is off');
    ok($M::joinCommand(['provider' => 'tailscale']) === 'zt tailscale enable --adopt' && $perm->commandIsPermitted('zt tailscale enable --adopt') && ! $perm->commandIsPermitted('zt tailscale enable')
        && $M::joinCommand(['provider' => 'netbird', 'key' => '', 'hostname' => 'web1']) === "zt netbird enable --adopt --hostname='web1'", 'join without a key: --adopt, so the CLI takes a connected client and never waits for a key; the bare command is not one the API runs');

    $meshOn = fn (string $p, array $more = []) => ['zt status' => ['output' => json_encode(['enabled' => false, 'tunnel' => null, 'mesh' => ['enabled' => true, 'provider' => $p, 'connected' => true, 'ip' => '100.64.0.7'] + $more]), 'exit_code' => 0, 'success' => true]];
    $meshOff = ['zt status' => ['output' => json_encode(['enabled' => false, 'tunnel' => null, 'mesh' => ['enabled' => false, 'provider' => null]]), 'exit_code' => 0, 'success' => true]];
    $mk = function (array $by, string $v = '5.18.0') use ($M, $Z) {
        $cli = new ScriptCli; $cli->by = $by;
        return [new $M($cli, new FakeVersion($v), new $Z($cli, new CipiApi\Services\CipiOutputParser), new CipiApi\Services\CipiIpWhitelistService($cli)), $cli];
    };
    [$ms, $cli] = $mk($meshOff, '5.17.2');
    foreach (['join' => fn () => $ms->join(['provider' => 'tailscale', 'key' => 'tskey-abcdef']), 'leave' => fn () => $ms->leave(''), 'lock' => fn () => $ms->lock('ssh', ''), 'unlock' => fn () => $ms->unlock('ssh'), 'ssh' => fn () => $ms->providerSsh(true)] as $what => $f) {
        $e = (string) thrown($f);
        ok(str_starts_with($e, 'RuntimeException: The mesh network from the panel needs Cipi 5.18.0+'), "an older core: {$what} is refused before anything runs");
    }
    ok($cli->ran === [], 'an older core: nothing ran');
    [$ms, $cli] = $mk($meshOff);
    ok($ms->clientIp('::ffff:203.0.113.9') === '203.0.113.9' && $ms->clientIp('100.64.0.2') === '100.64.0.2' && $ms->clientIp('fd7a:115c:a1e0::1') === 'fd7a:115c:a1e0::1' && $ms->clientIp("1.2.3.4'; id") === '' && $ms->clientIp(null) === '' && $ms->clientIp('unix:') === '', 'the caller\'s address: an IP as it is, anything else nothing');
    ok($ms->provider() === null, 'no mesh: provider is null');
    foreach (['leave' => fn () => $ms->leave('1.2.3.4'), 'lock' => fn () => $ms->lock('http', '1.2.3.4'), 'unlock' => fn () => $ms->unlock('http'), 'ssh' => fn () => $ms->providerSsh(true)] as $what => $f) {
        ok(thrown($f) === $R . ': This server is on no mesh network', "no mesh: {$what} has nothing to work on");
    }
    $cli->ran = [];
    $cli->by = ['zt tailscale enable' => ['output' => "\e[0;36m→\e[0m Installing Tailscale...\n\e[0;32m✓\e[0m Tailscale: web1 is on the network as 100.64.0.7", 'exit_code' => 0, 'success' => true]] + $meshOff;
    $out = $ms->join(['provider' => 'tailscale', 'key' => 'tskey-auth-k1', 'hostname' => 'web1']);
    ok($cli->ran === ["zt tailscale enable --auth-key='tskey-auth-k1' --hostname='web1'"] && $out === "Installing Tailscale...\nTailscale: web1 is on the network as 100.64.0.7", 'join: one command; what the CLI said, without colours');
    $cli->by = ['zt netbird enable' => ['output' => "\e[0;31m[ERROR]\e[0m The setup key A1B2C3D4-E5F6-7890 was refused by the management server", 'exit_code' => 1, 'success' => false]] + $meshOff;
    $e = (string) thrown(fn () => $ms->join(['provider' => 'netbird', 'key' => 'A1B2C3D4-E5F6-7890']));
    ok($e === $R . ': The setup key [key] was refused by the management server', 'a refused join: the CLI\'s words — and never the key, even if it repeats it');
    ok(str_contains((string) thrown(fn () => $ms->join(['provider' => 'netbird', 'key' => 'abcdefgh', 'ssh' => true])), "'ssh' is Tailscale's"), 'join validates again by itself: nothing runs on a bad request');

    [$ms, $cli] = $mk($meshOn('tailscale'));
    ok($ms->provider() === 'tailscale', 'provider: the mesh the server is on');
    $cli->ran = []; $ms->lock('ssh', '203.0.113.9');
    ok(end($cli->ran) === 'zt tailscale lock ssh --yes', 'lock ssh: nothing more than --yes (it is not the panel\'s way in)');
    $ms->lock('http', '203.0.113.9');
    ok(end($cli->ran) === "zt tailscale lock http --yes --via='203.0.113.9'", 'lock http: where the caller comes from goes with it, last');
    $ms->lock('http', '');
    ok(end($cli->ran) === 'zt tailscale lock http --yes', 'lock http with no address to give: the CLI then takes the caller for unknown, and refuses');
    $ms->lock('http', '127.0.0.1', '198.51.100.7');
    ok(end($cli->ran) === "zt tailscale lock http --yes --via='127.0.0.1' --client='198.51.100.7'", 'lock http from a panel on this server: the address of the person at it too');
    $ms->lock('ssh', '127.0.0.1', '198.51.100.7');
    ok(end($cli->ran) === 'zt tailscale lock ssh --yes', 'lock ssh never carries an address');
    $ms->unlock('ssh'); ok(end($cli->ran) === 'zt tailscale unlock ssh', 'unlock ssh');
    $ms->unlock('http'); ok(end($cli->ran) === 'zt tailscale unlock http', 'unlock http');
    $ms->leave('100.64.0.2'); ok(end($cli->ran) === "zt tailscale disable --yes --via='100.64.0.2'", 'leave: --yes and the caller\'s address');
    $ms->providerSsh(true); ok(end($cli->ran) === 'zt tailscale ssh enable', 'Tailscale SSH on');
    $ms->providerSsh(false); ok(end($cli->ran) === 'zt tailscale ssh disable', 'Tailscale SSH off');
    ok(thrown(fn () => $ms->lock('rdp', '')) === $R . ": 'port' is ssh or http" && thrown(fn () => $ms->unlock('22')) === $R . ": 'port' is ssh or http", 'a port that is neither ssh nor http');
    ok(! array_filter($cli->ran, fn ($c) => str_contains($c, '--force')), 'nothing the API runs carries --force');
    $cli->by = ['zt tailscale lock ssh' => ['output' => "\e[0;31m[ERROR]\e[0m Nobody has signed in over SSH through Tailscale yet. Do it once from a device of the network (ssh cipi@100.64.0.7): that login is the proof that it works, and then the port can be closed.", 'exit_code' => 1, 'success' => false]] + $meshOn('tailscale');
    $e = (string) thrown(fn () => $ms->lock('ssh', '203.0.113.9'));
    ok(str_starts_with($e, $R . ': Nobody has signed in over SSH through Tailscale yet.') && str_contains($e, 'ssh cipi@100.64.0.7'), 'a refused lock: the CLI\'s reason, whole');
    $cli->by = ['zt tailscale disable' => ['output' => '', 'exit_code' => 1, 'success' => false]] + $meshOn('tailscale');
    ok(thrown(fn () => $ms->leave('')) === $R . ': cipi zt tailscale disable failed', 'a failure that says nothing still says what failed');

    [$ms, $cli] = $mk($meshOn('netbird'));
    $cli->ran = []; $ms->lock('http', '100.64.0.2'); $ms->leave('::1');
    ok($cli->ran[1] === "zt netbird lock http --yes --via='100.64.0.2'" && $cli->ran[3] === "zt netbird disable --yes --via='::1'", 'NetBird: the same commands under its own name');
    $n = count($cli->ran);
    ok(str_contains((string) thrown(fn () => $ms->providerSsh(true)), "NetBird's own SSH server is not turned on by Cipi") && ! array_filter(array_slice($cli->ran, $n), fn ($c) => ! str_starts_with($c, 'zt status')), 'NetBird: its SSH server is not Cipi\'s to turn on — nothing runs');

    $cli = new ScriptCli; $cli->by = $meshOn('tailscale', ['panel' => ['via' => 'public', 'from' => '203.0.113.9', 'blocked' => ['lock_ssh' => null, 'lock_http' => 'The panel reaches this server\'s API on its public address', 'disable' => null]]]);
    $zs = (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status('203.0.113.9');
    ok($cli->ran === ["zt status --json --via='203.0.113.9'"] && ($zs['details']['mesh']['panel']['via'] ?? null) === 'public' && str_starts_with((string) $zs['details']['mesh']['panel']['blocked']['lock_http'], 'The panel reaches'), 'status with the caller\'s address: what the panel may do from there, as the CLI says it');
    $cli->ran = []; (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status(); (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status('');
    ok($cli->ran === ['zt status --json', 'zt status --json'], 'status without an address: the command as it always was');
    $cli->ran = []; (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status('127.0.0.1', '198.51.100.7'); (new $Z($cli, new CipiApi\Services\CipiOutputParser))->status(null, '198.51.100.7');
    ok($cli->ran === ["zt status --json --via='127.0.0.1' --client='198.51.100.7'", "zt status --json --client='198.51.100.7'"] && $perm->commandIsPermitted($cli->ran[0]), 'status from a panel on this server: the browser\'s address goes too');

    $routes = (string) file_get_contents($root . '/routes/api.php');
    foreach ([
        "Route::get('/zt', [ZtController::class, 'status'])->middleware('ability:zt-view')",
        "Route::post('/zt/mesh', [ZtMeshController::class, 'join'])->middleware('ability:zt-manage')",
        "Route::delete('/zt/mesh', [ZtMeshController::class, 'leave'])->middleware('ability:zt-manage')",
        "Route::post('/zt/mesh/lock', [ZtMeshController::class, 'lock'])->middleware('ability:zt-manage')",
        "Route::delete('/zt/mesh/lock', [ZtMeshController::class, 'unlock'])->middleware('ability:zt-manage')",
        "Route::put('/zt/mesh/ssh', [ZtMeshController::class, 'ssh'])->middleware('ability:zt-manage')",
    ] as $line) {
        ok(str_contains($routes, $line), 'route: ' . substr($line, 7, strpos($line, ',') - 7));
    }
    ok(substr_count($routes, 'ability:zt-manage') === 5 && ! preg_match('/zt\/mesh.*ability:zt-view/', $routes), 'every change of the mesh needs zt-manage; zt-view only reads');
    $cfg = (string) file_get_contents($root . '/config/cipi.php');
    ok(str_contains($cfg, "'zt-manage' => ") && str_contains($cfg, "'zt-view' => 'View Zero Trust status"), 'abilities: zt-manage is one; zt-view reads both sides');
    $ctl = (string) file_get_contents($root . '/src/Http/Controllers/ZtMeshController.php');
    ok(! str_contains($ctl, 'dispatch(') && ! str_contains($ctl, 'CipiJob') && str_contains($ctl, "'key' => 'nullable|string|max:512'") && substr_count($ctl, '], 422)') === 2 && str_contains($ctl, '], 503)'), 'the controller: synchronous (a key never reaches the jobs table); 422 for a refusal, 503 for an older core');
    ok(str_contains($ctl, "'client' => 'nullable|ip'") && str_contains((string) file_get_contents($root . '/src/Http/Controllers/ZtController.php'), "\$request->query('client')"), 'the browser\'s address is an IP or nothing, on the lock and on the status');
    $spec = json_decode((string) file_get_contents($root . '/public/api-docs/openapi.json'), true);
    $sc = $spec['components']['schemas'];
    ok(is_array($spec) && isset($spec['paths']['/zt/mesh']['post'], $spec['paths']['/zt/mesh']['delete'], $spec['paths']['/zt/mesh/lock']['post'], $spec['paths']['/zt/mesh/lock']['delete'], $spec['paths']['/zt/mesh/ssh']['put'], $sc['ZtMeshJoinRequest'], $sc['ZtMeshLockRequest'], $sc['ZtMeshSshRequest'], $sc['ZtMeshChangeResponse']), 'OpenAPI: the five routes of the mesh and their schemas');
    ok(str_contains(json_encode($spec['paths']['/zt/mesh']['post']), 'zt-manage') && str_contains(json_encode($spec['paths']['/zt/mesh/lock']['post']), 'zt-manage') && in_array('Zero Trust', array_column($spec['tags'], 'name'), true) && isset($spec['paths']['/zt/mesh']['post']['responses']['422'], $spec['paths']['/zt/mesh']['post']['responses']['503'], $sc['ZtMeshLockRequest']['properties']['client']) && ($spec['paths']['/zt']['get']['parameters'][0]['name'] ?? null) === 'client', 'OpenAPI: zt-manage, 422 and 503 on a change; client on the lock and on the status');
    $refs = []; array_walk_recursive($spec, function ($v, $k) use (&$refs) { if ($k === '$ref') { $refs[$v] = true; } });
    $missing = array_filter(array_keys($refs), function ($r) use ($spec) { $n = $spec; foreach (explode('/', substr($r, 2)) as $seg) { if (! isset($n[$seg])) { return true; } $n = $n[$seg]; } return false; });
    ok($missing === [], 'OpenAPI: every $ref still points at something' . ($missing ? ': ' . implode(', ', $missing) : ''));

    echo "--- deploy config: the two clocks of a deploy, and a Node app's options (Cipi 5.18.0)\n";
    require_once $root . '/src/Services/CipiAppDeployConfigService.php';
    $DCS = CipiApi\Services\CipiAppDeployConfigService::class;
    $dcApps = new class(new FakeVersion('5.18.0')) extends CipiApi\Services\CipiValidationService {
        public function getApps(): array { return ['shop' => ['php' => '8.5'], 'front' => ['custom' => true, 'runtime' => 'node'], 'static' => ['custom' => true]]; }
    };
    $dcCli = new FakeCli;
    $dcCli->next = ['output' => "\e[32m✓\e[0m Deploy config updated for shop (deploy.php regenerated)\n{\"app\":\"shop\",\"keep_releases\":5,\"build_timeout\":2700,\"step_timeout\":600}", 'exit_code' => 0, 'success' => true];
    $dc = new $DCS($dcCli, $dcApps, new FakeVersion('5.18.0'));
    ok($dc->buildFlags(['build_timeout' => 2700, 'step_timeout' => '600']) === ["--build-timeout='2700'", "--step-timeout='600'"] && $dc->buildFlags(['build_timeout' => null, 'keep_releases' => 3]) === ["--keep-releases='3'"],
        'build_timeout and step_timeout become the CLI\'s flags, in seconds; null leaves one alone');
    $refused = 0;
    foreach ([['build_timeout' => 59], ['build_timeout' => 3601], ['build_timeout' => '1800; id'], ['build_timeout' => 12.5], ['build_timeout' => true], ['step_timeout' => 1801], ['step_timeout' => 'abc'], ['step_timeout' => [300]]] as $bad) {
        try { $dc->buildFlags($bad); } catch (InvalidArgumentException $e) { $refused += (int) str_contains($e->getMessage(), 'must be a number of seconds between'); }
    }
    ok($refused === 8, 'out of bounds (60-3600, 60-1800), a fraction, a word, a command, a boolean, a list: each refused before the CLI is asked');
    $data = $dc->update('shop', ['build_timeout' => 2700, 'step_timeout' => 600]);
    ok($dcCli->ran === ["app deploy-config 'shop' --build-timeout='2700' --step-timeout='600' --json"] && $data['build_timeout'] === 2700 && $data['step_timeout'] === 600, 'update: one CLI call, and the answer is the config after it');
    $dcCli->ran = [];
    $dcCli->next = ['output' => '{"app":"front","runtime":"node","keep_releases":5,"predeploy_snapshot":false,"build_timeout":900,"step_timeout":300}', 'exit_code' => 0, 'success' => true];
    ok($dc->show('front')['runtime'] === 'node' && $dc->update('front', ['build_timeout' => 900, 'keep_releases' => 5, 'migrate' => null])['build_timeout'] === 900
        && $dcCli->ran === ["app deploy-config 'front' --json", "app deploy-config 'front' --keep-releases='5' --build-timeout='900' --json"], 'a Node app has deploy options too: keep_releases, predeploy_snapshot and the two clocks');
    $msg = ''; try { $dc->update('front', ['migrate' => false, 'build_timeout' => 900]); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
    ok(str_contains($msg, "'front' is a Node app") && str_contains($msg, 'not migrate') && count($dcCli->ran) === 2, 'an artisan option sent to a Node app is refused by name, and nothing runs');
    $msg = ''; try { $dc->show('static'); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
    ok(str_contains($msg, 'custom app'), 'a classic custom app has no recipe, as before');
    $oldCli = new FakeCli; $old = new $DCS($oldCli, $dcApps, new FakeVersion('5.17.0'));
    $msgs = [];
    foreach ([fn () => $old->update('shop', ['step_timeout' => 600]), fn () => $old->show('front')] as $call) { try { $call(); } catch (RuntimeException $e) { $msgs[] = $e->getMessage(); } }
    ok(count($msgs) === 2 && str_contains($msgs[0], 'step_timeout needs Cipi 5.18.0+') && str_contains($msgs[1], 'Node app need Cipi 5.18.0+') && $oldCli->ran === [], 'an older core is told so (503) instead of taking a flag for nothing');
    $oldCli->next = ['output' => '{"app":"shop","keep_releases":3}', 'exit_code' => 0, 'success' => true];
    ok($old->update('shop', ['keep_releases' => 3])['keep_releases'] === 3, '…and what it always knew still goes through');
    $dctl = (string) file_get_contents($root . '/src/Http/Controllers/DeployConfigController.php');
    ok(str_contains($dctl, "'build_timeout' => 'nullable|integer|min:60|max:3600'") && str_contains($dctl, "'step_timeout' => 'nullable|integer|min:60|max:1800'"), 'the controller validates both');
    $spec = json_decode((string) file_get_contents($root . '/public/api-docs/openapi.json'), true);
    $dcProps = $spec['components']['schemas']['DeployConfigUpdateRequest']['properties'];
    ok(($dcProps['build_timeout']['maximum'] ?? 0) === 3600 && ($dcProps['step_timeout']['maximum'] ?? 0) === 1800 && str_contains($spec['paths']['/apps/{name}/deploy-config']['put']['description'], 'Node app'), 'OpenAPI: the two fields, their bounds, the Node app');

    echo "--- how long a job may run (a deploy: two hours)\n";
    require_once $root . '/src/Services/CipiJobTimeout.php';
    $JT = CipiApi\Services\CipiJobTimeout::class;
    ok($JT::forCommand("deploy 'shop'") === 7200 && $JT::DEPLOY_SECONDS === 7200, "a deploy has two hours: the build alone may be given one of them");
    ok($JT::forCommand("deploy 'shop' --rollback") === 600 && $JT::forCommand("deploy 'shop' --unlock") === 600 && $JT::forCommand("deploy 'shop' --status --json") === 600, 'a rollback, an unlock, a status: ten minutes, as before');
    ok($JT::forCommand("app create --user='shop' --domain='shop.test'") === 600 && $JT::forCommand("backup run 'shop'") === 600 && $JT::forCommand('deploy') === 600 && $JT::forCommand('') === 600 && $JT::SECONDS === 600, 'every other command: ten minutes');
    ok($JT::isDeploy(" deploy 'shop' ") && ! $JT::isDeploy("app deploy-config 'shop' --json") && ! $JT::isDeploy("redeploy 'shop'"), 'a deploy is `deploy <app>` and nothing after it');
    $stopped = $JT::stoppedOutput("task deploy:vendors\ntask cipi:node_build\n", "deploy 'shop'", 7200);
    ok(str_starts_with($stopped, "task deploy:vendors\ntask cipi:node_build\n\n[cipi-api] Stopped after 120 minutes") && str_contains($stopped, '`cipi deploy shop --status`'), 'a deploy the worker stopped: what it had printed, then why and where to look');
    $stopped = $JT::stoppedOutput(null, "db restore 'shop' --file='/x.sql'", 600);
    ok($stopped === '[cipi-api] Stopped after 10 minutes: the time the API gives a job of this kind. The command had not ended.', 'another job stopped with nothing printed: the one line');
    $jobSrc = (string) file_get_contents($root . '/src/Jobs/RunCipiCommand.php');
    ok(str_contains($jobSrc, 'public int $timeout = CipiJobTimeout::SECONDS;') && str_contains($jobSrc, '$this->timeout = CipiJobTimeout::forCommand($command);') && str_contains($jobSrc, 'public int $tries = 1;'), 'RunCipiCommand: the limit is set when the job is made, and travels with it');
    ok((bool) preg_match('/public function failed\(\?\\\\Throwable \$e = null\): void.*?in_array\(\$job->status, \[\'pending\', \'running\'\], true\).*?instanceof TimeoutExceededException.*?CipiJobTimeout::EXIT_CODE.*?\$this->discardUpload\(\$job\);/s', $jobSrc)
        && $JT::EXIT_CODE === 124, 'RunCipiCommand::failed(): a job the worker gave up is closed as failed (124 for a timeout) instead of staying "running"; the dump of a restore is discarded');
    ok(! str_contains($jobSrc, '$e->getMessage()'), '…and the text of an exception never becomes the output of a job');
    // The job itself, with the queue and the jobs table stood in for.
    $GLOBALS['DISCARDED'] = [];
    if (! function_exists('app')) {
        function app(string $class): object { return new class { public function discard(string $id): void { $GLOBALS['DISCARDED'][] = $id; } }; }
    }
    require_once $root . '/src/Jobs/RunCipiCommand.php';
    $J = CipiApi\Jobs\RunCipiCommand::class; $M = CipiApi\Models\CipiJob::class;
    ok((new $J('a', "deploy 'shop'"))->timeout === 7200 && (new $J('a', "deploy 'shop' --rollback"))->timeout === 600 && (new $J('a', "ssl install 'shop'"))->timeout === 600 && (new $J('a', "deploy 'shop'"))->tries === 1,
        'a job is made with its limit: 7200 for a deploy, 600 for the rest, one try');
    $M::$rows = ['d1' => new $M('running', "task deploy:vendors\ntask cipi:node_build")];
    (new $J('d1', "deploy 'shop'"))->failed(new Illuminate\Queue\TimeoutExceededException('App\Jobs has timed out.'));
    ok($M::$rows['d1']->status === 'failed' && $M::$rows['d1']->exit_code === 124 && str_starts_with($M::$rows['d1']->output, "task deploy:vendors\ntask cipi:node_build\n\n[cipi-api] Stopped after 120 minutes")
        && ! str_contains($M::$rows['d1']->output, 'timed out'), 'a deploy stopped at its two hours: failed, 124, its output kept, the reason under it');
    $M::$rows = ['r1' => new $M('running', 'Restoring…', 'db-restore', ['upload_id' => 'abc'])];
    (new $J('r1', "db restore 'shop'"))->failed(new RuntimeException('SQLSTATE[HY000]: database is locked (password=hunter2)'));
    ok($M::$rows['r1']->status === 'failed' && $M::$rows['r1']->exit_code === 1 && $M::$rows['r1']->output === "Restoring…\n\n[cipi-api] The job ended with an error before the result of the command was saved."
        && $GLOBALS['DISCARDED'] === ['abc'], 'a job whose handler threw: failed, 1, no word of the exception; the uploaded dump is discarded');
    $M::$rows = ['c1' => new $M('completed', 'successfully deployed!', 'app-deploy', null, 0)];
    (new $J('c1', "deploy 'shop'"))->failed(new Illuminate\Queue\MaxAttemptsExceededException('attempted too many times'));
    ok($M::$rows['c1']->status === 'completed' && $M::$rows['c1']->output === 'successfully deployed!' && $M::$rows['c1']->exit_code === 0, 'a job that had ended keeps its result, whatever the queue says afterwards');
    (new $J('gone', "deploy 'shop'"))->failed(null);
    ok(true, 'a job that is not in the table any more: nothing to close, nothing thrown');
    ok(str_contains((string) file_get_contents($root . '/CHANGELOG.md'), 'CipiJobTimeout') && str_contains((string) file_get_contents($root . '/README.md'), 'a deploy gets two hours'), 'CHANGELOG and README say it');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
