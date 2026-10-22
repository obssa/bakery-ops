<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Shifts\Actions\ShiftResolution;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * manifest shifts.provides "Locks::previousClosing(location)" and what goes with it (phase-3 plan section 4, "How a count works"; section
 * 6 item 4; ADR 0012). Branch-scoped through the model (rule 8); resolved per use.
 *
 *   previousClosing: the closing count an opening count is prefilled from and measured against: the latest closing of the place that is
 *     `approved` or `locked` and not superseded, the newest `submitted_at` first, then the newest id. (`approved` is the instant between
 *     the decision and the lock, and the state the deploys before step 3.4 left counts in.) A closing that still waits for a
 *     co-signature or a decision is not carried: it is the unresolved count that rule R-A names when the next count is blocked; a
 *     closing that was unlocked is not carried either, its revision is.
 *   unresolvedAt: the unresolved count at the place (awaiting a co-signature or a decision), the newest first, other than $exceptId.
 *   supersede: gives a locked count its revision. The only write a locked row accepts (migration 12's trigger): one query-builder UPDATE
 *     of superseded_by_id and superseded_at, from NULL, so neither `updated_at` nor `version` moves and the link is exactly what was hashed.
 *   lockApproved: locks every count left `approved` (see below), the work migration 12 ends with and `shifts:lock-approved` repeats.
 */
final class Locks
{
    public function __construct(
        private readonly HashChain $chain,
        private readonly CurrentBranch $current,
    ) {}

    /** The latest approved or locked closing count of the place that was not superseded, or null for a place that was never closed (or whose closings wait). */
    public function previousClosing(int $locationId): ?StockCount
    {
        return StockCount::query()
            ->where('location_id', $locationId)
            ->where('type', CountType::Closing->value)
            ->whereIn('status', [CountStatus::Approved->value, CountStatus::Locked->value])
            ->whereNull('superseded_by_id')
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

    /**
     * Point a locked count at its revision. Refused (nothing written) unless the count is locked and has no revision yet.
     *
     * @return bool whether the row was superseded by this call
     */
    public function supersede(StockCount $old, StockCount $revision, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();
        $changed = DB::table('stock_counts')
            ->where('id', $old->id)->where('status', CountStatus::Locked->value)->whereNull('superseded_by_id')
            ->update(['superseded_by_id' => $revision->id, 'superseded_at' => $at->utc()->format('Y-m-d H:i:s')]);
        if ($changed === 1) {
            $old->setRawAttributes(array_merge($old->getAttributes(), ['superseded_by_id' => $revision->id, 'superseded_at' => $at->utc()->format('Y-m-d H:i:s')]), true);
        }

        return $changed === 1;
    }

    /**
     * Lock every count of every branch that is `approved`: the counts the deploys between steps 3.3b and 3.4 left without a hash. Each in
     * its own transaction (count row locked first, then its shift, as the actions do), oldest decision (else submission) first within a
     * place so the chain keeps the order things happened in, locked by the person who decided it (else the one who counted it). The
     * shift of a count that was its last unresolved one locks with it. Idempotent: a locked count is not `approved`, so a second run
     * finds nothing. A count that fails (logged) stays `approved`, still carried by previousClosing, for the next run.
     *
     * @return int how many counts were locked
     */
    public function lockApproved(): int
    {
        $locked = 0;
        $previous = $this->current->isResolved() ? $this->current->get() : null;

        try {
            foreach (Branch::query()->orderBy('id')->get() as $branch) {
                $this->current->set($branch);
                $ids = DB::table('stock_counts')
                    ->where('branch_id', $branch->id)->where('status', CountStatus::Approved->value)
                    ->orderBy('location_id')->orderByRaw('coalesce(decided_at, submitted_at)')->orderBy('id')
                    ->pluck('id');
                foreach ($ids as $id) {
                    try {
                        if (DB::transaction(fn (): bool => $this->lockOne((int) $id))) {
                            $locked++;
                        }
                    } catch (Throwable $e) {
                        Log::warning('stock_counts: an approved count could not be locked; it stays approved', ['count_id' => (int) $id, 'error' => $e->getMessage()]);
                    }
                }
            }
        } finally {
            $previous === null ? $this->current->clear() : $this->current->set($previous);
        }

        return $locked;
    }

    private function lockOne(int $id): bool
    {
        $count = StockCount::query()->whereKey($id)->lockForUpdate()->first();
        if (! $count instanceof StockCount || $count->status !== CountStatus::Approved) {
            return false;
        }
        $shift = ShiftInstance::query()->whereKey($count->shift_instance_id)->lockForUpdate()->firstOrFail();
        $by = $count->decided_by_user_id ?? $count->counted_by_user_id ?? $count->created_by_user_id;

        $this->chain->lock($count, $by);
        ShiftResolution::lockWhenResolved($shift, CarbonImmutable::now()->startOfSecond());

        return true;
    }
}
