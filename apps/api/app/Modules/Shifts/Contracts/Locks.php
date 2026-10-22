<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\StockCount;

/**
 * manifest shifts.provides "Locks::previousClosing(location)": the closing count an opening count is prefilled from and measured
 * against (phase-3 plan section 4, "How a count works"; section 6 item 3). In step 3.3a, before anything is locked, it is the
 * latest closing of the place that is `approved` (the newest `submitted_at`, then the newest id); step 3.4 widens it to
 * `approved` or `locked` and not superseded, and adds `supersede`. A closing that still waits for a co-signature or a decision is
 * not carried: it is the unresolved count that rule R-A (one per place) names when the next count is blocked.
 * Branch-scoped through the model (rule 8); resolved per use.
 */
final class Locks
{
    /** The latest approved closing count of the place, or null for a place that was never closed (or whose closings wait). */
    public function previousClosing(int $locationId): ?StockCount
    {
        return StockCount::query()
            ->where('location_id', $locationId)
            ->where('type', CountType::Closing->value)
            ->where('status', CountStatus::Approved->value)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }

    /** The unresolved count at the place (awaiting a co-signature or a decision), the newest first, other than $exceptId. */
    public function unresolvedAt(int $locationId, ?int $exceptId = null): ?StockCount
    {
        return StockCount::query()
            ->where('location_id', $locationId)
            ->whereIn('status', [CountStatus::AwaitingCosign->value, CountStatus::PendingApproval->value])
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderByDesc('id')
            ->first();
    }
}
