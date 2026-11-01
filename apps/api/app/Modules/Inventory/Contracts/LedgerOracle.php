<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Ledger\Reconciliation;

/**
 * The ledger's own reconciliation, on any connection (phase-5 plan section 2, contract ops->inventory, step 5.5b; ADR 0002): the restore
 * drill of App\Ops asks it about a database restored from last night's dump, through a named connection.
 *
 * check() returns {ok, items, locations, mismatches}: the stock movements added up by the ledger's own rule (MovementReason) against the
 * stock balances and against the stock view of that database, each difference a pair of its own named by its source, with both figures
 * as decimal strings at scale 4 (Ledger\Reconciliation does the reading). It reads only the named connection and writes nothing. A
 * final class resolved per use (it carries nothing).
 */
final class LedgerOracle
{
    public function __construct(private readonly Reconciliation $reconciliation) {}

    public function check(string $connection): LedgerCheck
    {
        $result = $this->reconciliation->compare($connection);

        return new LedgerCheck($result['items'], $result['locations'], $result['mismatches']);
    }
}
