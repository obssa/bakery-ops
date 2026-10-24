<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use Illuminate\Database\Query\Builder;

/**
 * The query side of Inventory\Contracts\Facts for the ledger, inside the Ledger namespace because nothing else may touch the
 * movement model (tests/Arch/LedgerIsSoleWriterTest.php). Read-only: it hands back a base query on the movements of the current
 * branch (BranchScoped) in a UTC window [start, end); the Facts port chunks and maps it.
 */
final class FactsReader
{
    public function movementsBetween(string $startUtc, string $endUtc): Builder
    {
        return StockMovement::query()->toBase()
            ->select('id', 'item_id', 'from_location_id', 'to_location_id', 'qty', 'reason', 'ref_type', 'ref_id', 'shift_instance_id', 'created_at')
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc);
    }
}
