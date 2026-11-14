<?php

declare(strict_types=1);

use App\Ops\Backup\MysqlClient;
use App\Ops\Models\OpsRun;
use App\Shared\Models\Branch;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\OpsFixture;
use Tests\Support\ScratchCopy;

/*
 * ADR 0028: the shared host kills any statement after 20 seconds (max_statement_time, global and per session), and a large table's single
 * SELECT in the dump, or one statement of a restore, can take longer. Here the same limit is set far lower on the test server (10 ms), as
 * root (compose.yml's throwaway MARIADB_ROOT_PASSWORD; OPS_DB_ROOT_PASSWORD overrides it), so that a table of about 120 MB is slower to read
 * than the limit, and the real mysqldump and mysql of the API image (the MariaDB 10.11 clients of the live host) must still finish:
 *   - the dump under the server's limit, and under a host option file (~/.my.cnf) that sets mysqldump's own max-statement-time;
 *   - the restore of a statement slower than the limit.
 * Each test first shows that the limit really stops a plain statement on a fresh connection. The global limit is put back after each test.
 */

pest()->group('ops');

uses(CommitsForConcurrency::class);

beforeEach(function () {
    $this->ops = new OpsFixture;
    $this->ops->emptyScratch();
    // a connection like the default one, opened now (its session takes the global limit as it is at this moment)
    $this->connect = function (string $name, array $override = []): Connection {
        $default = (string) config('database.default');
        config(["database.connections.{$name}" => $override + ['url' => null] + (array) config("database.connections.{$default}")]);
        DB::purge($name);

        return DB::connection($name);
    };
    $this->root = ($this->connect)('ops_root', ['username' => 'root', 'password' => (string) (getenv('OPS_DB_ROOT_PASSWORD') ?: 'root')]);
    $this->globalLimit = (float) $this->root->selectOne('SELECT @@GLOBAL.max_statement_time AS t')->t;
    $this->home = getenv('HOME');
    // 40,000 stored answers of about 3 KB, committed (the dump's own connection must see them)
    $this->fill = function (): void {
        $branch = Branch::factory()->create();
        DB::statement(
            'INSERT INTO idempotency_keys (branch_id, user_id, `key`, request_hash, response_status, response_body, expires_at, created_at, updated_at) '
            ."SELECT ?, 1, CONCAT('slow-', seq), REPEAT('0', 64), 201, CONCAT('{\"kind\":\"raw\",\"data\":\"', REPEAT('x', 3000), '\"}'), "
            .'NOW() + INTERVAL 1 DAY, NOW(), NOW() FROM seq_1_to_40000',
            [$branch->id],
        );
    };
    // the error number of a statement run on a fresh connection, null when it passes
    $this->errorOf = function (string $sql, array $override = []): ?int {
        try {
            ($this->connect)('ops_limited', $override)->statement($sql);

            return null;
        } catch (QueryException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        } finally {
            DB::purge('ops_limited');
        }
    };
    // whether the gzipped dump holds a text, read in pieces (the dump is too large to hold in memory twice)
    $this->dumpHolds = function (string $gzPath, string $needle): bool {
        $gz = gzopen($gzPath, 'rb');
        $carry = '';
        while ($gz !== false && ! gzeof($gz)) {
            $piece = $carry.gzread($gz, 1048576);
            if (str_contains($piece, $needle)) {
                gzclose($gz);

                return true;
            }
            $carry = substr($piece, -strlen($needle));
        }
        if ($gz !== false) {
            gzclose($gz);
        }

        return false;
    };
});

afterEach(function () {
    $this->root->statement('SET GLOBAL max_statement_time = '.sprintf('%.6F', $this->globalLimit));
    $this->home === false ? putenv('HOME') : putenv('HOME='.$this->home);
    DB::purge('ops_root');
    $this->ops->emptyScratch();
    $this->ops->tearDown();
});

it('dumps a table slower to read than the server\'s statement limit', function () {
    ($this->fill)();
    $this->root->statement('SET GLOBAL max_statement_time = 0.01');

    expect(($this->errorOf)('SELECT SUM(LENGTH(response_body)) FROM idempotency_keys'))->toBe(1969, 'the limit stops a plain read of the table');

    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());

    $run = OpsRun::query()->where('kind', 'backup')->sole();
    expect($run->status)->toBe('ok')
        ->and(($this->dumpHolds)($this->ops->folder.'/'.$run->path_hint, "'slow-40000'"))->toBeTrue('the last row of the slow table is in the dump');
});

it('dumps it when an option file of the host sets mysqldump\'s own limit, which a dump without --max-statement-time=0 obeys', function () {
    ($this->fill)();
    $home = dirname($this->ops->folder).'/home';
    mkdir($home, 0700, true);
    file_put_contents($home.'/.my.cnf', "[mysqldump]\nmax-statement-time=0.01\n");
    // the credentials of the test server (never the live host's) in an option file of the test, for the control below
    $db = DB::connection();
    $options = $home.'/control.cnf';
    file_put_contents($options, sprintf("[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\nport=%d\n",
        $db->getConfig('username'), $db->getConfig('password'), $db->getConfig('host'), (int) ($db->getConfig('port') ?: 3306)));
    chmod($options, 0600);

    $control = Process::env(['HOME' => $home])->run([
        (string) config('backup.binaries.mysqldump', 'mysqldump'), '--defaults-extra-file='.$options,
        '--single-transaction', '--quick', '--no-tablespaces', (string) $db->getDatabaseName(), 'idempotency_keys',
    ]);
    expect($control->exitCode())->toBe(3)
        ->and($control->errorOutput())->toContain('1969', 'the host file alone stops the dump of the table');

    putenv('HOME='.$home);
    expect(Artisan::call('backup:run'))->toBe(0, Artisan::output());

    $run = OpsRun::query()->where('kind', 'backup')->sole();
    expect($run->status)->toBe('ok')
        ->and(($this->dumpHolds)($this->ops->folder.'/'.$run->path_hint, "'slow-40000'"))->toBeTrue();
});

it('restores a statement slower than the server\'s statement limit', function () {
    $this->root->statement('SET GLOBAL max_statement_time = 0.01');
    $scratch = ['database' => ScratchCopy::database()];
    $slow = 'INSERT INTO slow_restore SELECT seq FROM seq_1_to_1000000';
    ($this->connect)('ops_setup', $scratch)->statement('CREATE TABLE slow_restore (n BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    DB::purge('ops_setup');
    expect(($this->errorOf)($slow, $scratch))->toBe(1969, 'the limit stops the statement on a fresh connection');
    ($this->connect)('ops_setup', $scratch)->statement('DROP TABLE slow_restore');
    DB::purge('ops_setup');

    mkdir($this->ops->folder, 0700, true);
    $gz = $this->ops->folder.'/slow.sql.gz';
    file_put_contents($gz, gzencode("CREATE TABLE slow_restore (n BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n{$slow};\n"));

    app(MysqlClient::class)->restore($gz, DB::connection('ops_probe'), $this->ops->folder);

    expect(DB::connection('ops_probe')->table('slow_restore')->count())->toBe(1000000);
});

it('runs both clients with the limit lifted on the command line, and keeps the option file to the credentials and the server', function () {
    $client = app(MysqlClient::class);

    expect($client->dumpArguments('/x/.client-1', 'db'))->toContain('--max-statement-time=0')
        ->and($client->restoreArguments('/x/.client-1', 'db'))->toContain('--init-command=SET SESSION max_statement_time=0')
        ->and($client->restoreArguments('/x/.client-1', 'db')[1])->toBe('--defaults-extra-file=/x/.client-1')
        ->and(implode(' ', $client->dumpArguments('/x/.client-1', 'db')))->not->toContain('init-command', 'mysqldump refuses init-command (exit 7)');
});
