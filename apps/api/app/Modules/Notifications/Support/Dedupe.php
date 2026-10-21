<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Models\NotificationDedupe;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;

/**
 * The delivery gate of an episodic alert (plan section 3, migration 13; ADR 0011): "have people already been told about this
 * item running out here?". claim() is ONE insert that the unique key (branch_id, dedupe_key, is_active) arbitrates: it
 * inserts the active row, or finds there already is one and says so. It never reads first and never takes SELECT ... FOR
 * UPDATE on a row that may not exist yet, which locks nothing and gap-locks (traps.md, "First-row race"); two callers
 * claiming at once see exactly one winner. clear() closes the active row when StockRecovered arrives (is_active NULL,
 * cleared_at set); the row stays as history.
 *
 * Resolved per use: claim and isActive work in the CurrentBranch; clear takes the event's branch, because a worker may have
 * resolved another.
 */
final class Dedupe
{
    public function __construct(private readonly CurrentBranch $branch) {}

    /** True when this caller is the first to claim $key (the people have not been told yet), false when it is held. */
    public function claim(string $key, string $type): bool
    {
        $branchId = $this->branch->id();

        return NotificationDedupe::query()->insertOrIgnore([
            'branch_id' => $branchId,
            'dedupe_key' => $key,
            'type' => $type,
            'is_active' => 1,
            'active_since' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            'cleared_at' => null,
        ]) === 1;
    }

    public function isActive(string $key): bool
    {
        $branchId = $this->branch->id();

        return NotificationDedupe::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)->where('dedupe_key', $key)->where('is_active', 1)->exists();
    }

    /** Open the gate again: true when there was an active row to close. */
    public function clear(int $branchId, string $key): bool
    {
        return NotificationDedupe::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)->where('dedupe_key', $key)->where('is_active', 1)
            ->update(['is_active' => null, 'cleared_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u')]) > 0;
    }
}
