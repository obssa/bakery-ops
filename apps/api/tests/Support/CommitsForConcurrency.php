<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * For tests that need a second database connection to see committed rows (group `concurrency`; traps.md, Pest +
 * Postgres): DatabaseTruncation instead of RefreshDatabase, whose wrapping transaction would hide every row from the
 * second connection. Two additions keep the rest of the suite (RefreshDatabase, one process) healthy:
 *   - `units` is never truncated: its standard rows are inserted by their migration and every later test reads them;
 *   - the tables are truncated again after the test, so nothing it committed leaks into the tests that follow.
 */
trait CommitsForConcurrency
{
    use DatabaseTruncation;

    /**
     * @return list<string>
     */
    protected function exceptTables(ConnectionInterface $connection, ?string $connectionName): array
    {
        return ['migrations', 'units'];
    }

    protected function tearDownCommitsForConcurrency(): void
    {
        $this->truncateTablesForAllConnections();
    }
}
