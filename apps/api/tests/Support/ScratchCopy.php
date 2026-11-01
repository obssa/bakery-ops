<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A scratch copy of the test database on the same server, for the ops contracts (phase-5 plan section 2, step 5.5b): the consumer
 * (the restore drill) reads a restored database through a named connection, so the providers are proven on a second database whose
 * rows can be tampered with while the default connection keeps the originals.
 *
 * ofDefault() points the connection `scratch` at SCRATCH_DB_DATABASE (default `bakery_scratch`, created beside bakery_test by
 * scripts/ci/mysql-prepare.sh in CI and by docker/mysql/init.sql on a fresh compose volume), migrates it when its migrations differ
 * from the default's (once per process in practice), then makes its rows those of the default connection as the test sees them:
 * the copy is read on the default connection, inside the test's RefreshDatabase transaction, and written on the scratch one, which
 * commits on its own. The scratch's triggers are dropped for the copy (a locked count's lines refuse an INSERT otherwise) and put
 * back exactly as SHOW CREATE TRIGGER printed them, so the copy has the protections of the original.
 *
 * withoutTriggers() is the person with write access to the scratch database: it drops the triggers of a table, runs the tamper with
 * raw statements and puts them back. Nothing here ever writes on the default connection.
 */
final class ScratchCopy
{
    public const string CONNECTION = 'scratch';

    public static function database(): string
    {
        $name = trim((string) getenv('SCRATCH_DB_DATABASE'));

        return $name === '' ? 'bakery_scratch' : $name;
    }

    /** The scratch connection, holding a copy of the default connection's rows. */
    public static function ofDefault(): string
    {
        self::configure();
        self::migrate();
        self::copyRows();

        return self::CONNECTION;
    }

    public static function connection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * @template T
     *
     * @param  Closure(Connection): T  $tamper
     * @return T
     */
    public static function withoutTriggers(string $table, Closure $tamper): mixed
    {
        $db = self::connection();
        $names = array_map(
            static fn (object $row): string => (string) $row->name,
            $db->select('select trigger_name as name from information_schema.triggers where trigger_schema = database() and event_object_table = ?', [$table]),
        );

        return self::dropping($db, $names, fn (): mixed => $tamper($db));
    }

    /**
     * Run $run while the view $name of the scratch database is `$select` instead of its migrated definition, then put the definition
     * back as SHOW CREATE VIEW printed it (the scratch is reused by the next test).
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    public static function replacingView(string $name, string $select, Closure $run): mixed
    {
        $db = self::connection();
        $row = (array) $db->selectOne("SHOW CREATE VIEW `{$name}`");
        $original = (string) ($row['Create View'] ?? '');
        if (! str_starts_with($original, 'CREATE ')) {
            Assert::fail("SHOW CREATE VIEW {$name} printed nothing on the scratch database");
        }
        $db->statement("CREATE OR REPLACE VIEW `{$name}` AS {$select}");

        try {
            return $run();
        } finally {
            $db->statement('CREATE OR REPLACE '.substr($original, strlen('CREATE ')));
        }
    }

    private static function configure(): void
    {
        $default = (string) config('database.default');
        $config = (array) config("database.connections.{$default}");
        $config['database'] = self::database();
        $config['url'] = null;
        config(['database.connections.'.self::CONNECTION => $config]);
        DB::purge(self::CONNECTION);

        try {
            self::connection()->getPdo();
        } catch (Throwable $e) {
            Assert::fail(sprintf(
                'The scratch database %s is not reachable as the test user (%s). Create it beside the test database and grant the test user on it '
                .'(scripts/ci/mysql-prepare.sh in CI, docker/mysql/init.sql on a fresh compose volume).',
                self::database(),
                $e->getMessage(),
            ));
        }
    }

    private static function migrate(): void
    {
        $db = self::connection();
        $want = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $have = $db->getSchemaBuilder()->hasTable('migrations') ? $db->table('migrations')->orderBy('migration')->pluck('migration')->all() : [];
        if ($have === $want) {
            return;
        }
        $code = Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]);
        if ($code !== 0) {
            Assert::fail('migrate:fresh on the scratch database failed: '.Artisan::output());
        }
    }

    private static function copyRows(): void
    {
        $source = DB::connection();
        $db = self::connection();
        // getTables() lists every schema the user can see unless it is named (Laravel 12), and the test user sees the scratch too
        $tables = array_values(array_filter(
            array_map(static fn (array $t): string => (string) $t['name'], $source->getSchemaBuilder()->getTables($source->getDatabaseName())),
            static fn (string $name): bool => $name !== 'migrations',
        ));
        $triggers = array_map(
            static fn (object $row): string => (string) $row->name,
            $db->select('select trigger_name as name from information_schema.triggers where trigger_schema = database()'),
        );

        self::dropping($db, $triggers, function () use ($source, $db, $tables): void {
            $db->statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($tables as $table) {
                    $db->table($table)->delete();
                    foreach ($source->table($table)->get()->chunk(500) as $chunk) {
                        $db->table($table)->insert($chunk->map(static fn (object $row): array => (array) $row)->all());
                    }
                }
            } finally {
                $db->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }

    /**
     * @template T
     *
     * @param  list<string>  $names
     * @param  Closure(): T  $run
     * @return T
     */
    private static function dropping(Connection $db, array $names, Closure $run): mixed
    {
        $definitions = [];
        foreach ($names as $name) {
            $row = (array) $db->selectOne("SHOW CREATE TRIGGER `{$name}`");
            $definitions[$name] = (string) ($row['SQL Original Statement'] ?? '');
            if ($definitions[$name] === '') {
                Assert::fail("SHOW CREATE TRIGGER {$name} printed nothing on the scratch database");
            }
        }
        foreach ($names as $name) {
            $db->unprepared("DROP TRIGGER `{$name}`");
        }

        try {
            return $run();
        } finally {
            foreach ($definitions as $sql) {
                $db->unprepared($sql);
            }
        }
    }
}
