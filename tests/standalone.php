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
namespace {
    $GLOBALS['T'] = sys_get_temp_dir() . '/cipi-api-t-' . getmypid();
    $GLOBALS['CFG'] = ['cipi.db_upload.max_mb' => 1, 'cipi.db_upload.chunk_mb' => 1, 'cipi.db_upload.spool_mb' => 1];
    function storage_path(string $p = ''): string { return $GLOBALS['T'] . '/storage/' . $p; }
    function config(string $k, mixed $d = null): mixed { return $GLOBALS['CFG'][$k] ?? $d; }
    $src = ($argv[1] ?? dirname(__DIR__)) . '/src/Services/';
    foreach (['CipiCliService', 'CipiVersionService', 'CipiDbUploadService', 'CipiBackupCliService', 'CipiDeployHistoryCliService'] as $c) {
        require $src . $c . '.php';
    }

    class FakeVersion extends CipiApi\Services\CipiVersionService {
        public function __construct(public ?string $v) {}
        public function installed(): ?string { return $this->v; }
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
    ok(str_starts_with((string) thrown(fn () => (new CipiApi\Services\CipiDbUploadService(new FakeVersion('5.8.6')))->requireCore()), 'RuntimeException: Uploading a database dump needs Cipi 5.9.0+'), 'an older core is refused before anything is stored');
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
    ok(str_contains((string) thrown(fn () => (new CipiApi\Services\CipiBackupCliService(new FakeCli, new FakeVersion('5.8.6')))->status()), 'needs Cipi 5.9.0+'), 'an older core is refused');
    $n = count($cli->ran); $r = $cli->run('backup configure'); $r2 = $cli->run('backup destination add x --secret=s'); $r3 = $cli->run('backup run --profile=x'); $r4 = $cli->run('backup key show');
    ok(! $r['success'] && ! $r2['success'] && ! $r3['success'] && ! $r4['success'] && count($cli->ran) === $n, 'not on the allow-list: backup configure, destination add, key, a run that is not detached');

    echo "--- deploy history\n";
    $cli = new FakeCli; $dh = new CipiApi\Services\CipiDeployHistoryCliService($cli, new FakeVersion('5.9.0'));
    $cli->next = ['output' => '{"app":"shop","count":1,"runs":[{"result":"ok","duration":41}],"log":"/x"}', 'exit_code' => 0, 'success' => true];
    $h = $dh->history('shop', 9999);
    ok($cli->ran === ["deploy --history 'shop' --json --limit=500"] && $h['runs'][0]['duration'] === 41, 'history: the flag goes first (an older core cannot deploy on it), the limit is capped');
    $cli->next = ['output' => '{"apps":{"shop":{"result":"ok"},"blog":null}}', 'exit_code' => 0, 'success' => true];
    $l = $dh->last();
    ok(end($cli->ran) === 'deploy --last --json' && array_key_exists('blog', $l) && $l['blog'] === null, 'last: every app, null for one never deployed');
    $old = new FakeCli; $dho = new CipiApi\Services\CipiDeployHistoryCliService($old, new FakeVersion('5.8.6'));
    ok(str_contains((string) thrown(fn () => $dho->history('shop')), 'needs Cipi 5.9.0+') && str_contains((string) thrown(fn () => $dho->last()), 'needs Cipi 5.9.0+') && $old->ran === [], 'an older core: refused, and nothing is run');
    foreach (["node install '22'", "node default 'system'", 'node upgrade', "node upgrade '24' --restart", "node remove '20'", "db link 'wpblog' 'blog'", "db unlink 'wpblog' --engine='mariadb'", "db create --name='x' --app='blog'", "db list --json", "backup verify --profile='x'"] as $c) {
        if (! $cli->commandIsPermitted($c)) { ok(false, "not permitted: {$c}"); }
    }
    ok(true, 'the Node, link and verify commands the controllers build are on the allow-list');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
