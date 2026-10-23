<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Hash;

use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Models\VarianceReason;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The payload of a chain link, version 1 (ADR 0012; phase-3 plan section 10.3): everything that was decided about a count, read from the
 * live rows, plus the three things the lock itself adds (chain_seq, locked_at, locked_by_user_id). Every key of the schema is always
 * present, null where it has no value; lines are ordered by item id; decimals are fixed-scale strings; instants are UTC with six
 * fraction digits. The same builder rebuilds a stored payload from today's rows for verification (rebuild), so what it reads must not
 * change after the lock: the count and its lines are frozen by trigger, a shift's date and station do not move, a reason's code is
 * never edited, and a movement is append-only.
 *
 * `adjustment_qty` is the quantity of the movement the approval wrote for the line, which is by construction the line's variance
 * (a delta, never "set to the counted figure", ApproveCount); it is read from the line, so Shifts needs no view of the ledger's
 * movement rows. `cash` is null for a stock count and, for a till count, the block of its declaration (CashDeclarations::payload): the keys scripts/verify_chain.py knows, rebuilt from the frozen declaration row. Resolved per use.
 */
final class PayloadBuilder
{
    public const int VERSION = 1;

    public function __construct(private readonly CashDeclarations $cash) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StockCount $count, int $chainSeq, CarbonImmutable $lockedAt, int $lockedByUserId): array
    {
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        /** @var list<StockCountLine> $lines */
        $lines = array_values(StockCountLine::query()->where('count_id', $count->id)->orderBy('item_id')->get()->all());
        $reasonIds = array_values(array_unique(array_filter(array_map(static fn (StockCountLine $l): ?int => $l->variance_reason_id, $lines))));
        $codes = $reasonIds === [] ? [] : VarianceReason::query()->withTrashed()->whereKey($reasonIds)->pluck('code', 'id')->all();
        $supersedes = StockCount::query()->where('superseded_by_id', $count->id)->value('id');

        return [
            'branch_id' => $count->branch_id,
            'location_id' => $count->location_id,
            'shift_instance_id' => $count->shift_instance_id,
            'shift_date' => CanonicalJson::date($shift->date),
            'station_id' => $shift->station_id,
            'type' => $count->type->value,
            'seq' => $count->seq,
            'supersedes_count_id' => $supersedes === null ? null : (int) $supersedes,
            'snapshot_at' => CanonicalJson::timestamp($count->snapshot_at),
            'ledger_movement_id' => $count->ledger_movement_id,
            'counted_by_user_id' => $count->counted_by_user_id,
            'cosigned_by_user_id' => $count->cosigned_by_user_id,
            'decision' => $count->decision?->value,
            'decided_by_user_id' => $count->decided_by_user_id,
            'decided_at' => CanonicalJson::timestamp($count->decided_at),
            'decision_note' => $count->decision_note,
            'submitted_at' => CanonicalJson::timestamp($count->submitted_at),
            'lines' => array_map(fn (StockCountLine $line): array => $this->line($line, $codes), $lines),
            'cash' => $this->cash->payload($count), // a cash-drawer count's declaration (step 3.5); null for a stock count
            'chain_seq' => $chainSeq,
            'locked_at' => CanonicalJson::timestamp($lockedAt),
            'locked_by_user_id' => $lockedByUserId,
            'payload_version' => self::VERSION,
        ];
    }

    /** The canonical bytes of the payload: what is hashed and stored. */
    public function canonical(StockCount $count, int $chainSeq, CarbonImmutable $lockedAt, int $lockedByUserId): string
    {
        return CanonicalJson::encode($this->build($count, $chainSeq, $lockedAt, $lockedByUserId));
    }

    /**
     * The bytes a locked count's live rows produce today, by the rules of the version it was locked under.
     *
     * @throws LogicException a payload version this code does not know
     */
    public function rebuild(StockCount $locked): string
    {
        if ($locked->payload_version !== self::VERSION) {
            throw new LogicException("No payload builder for version {$locked->payload_version}.");
        }
        if ($locked->chain_seq === null || $locked->locked_at === null || $locked->locked_by_user_id === null) {
            throw new LogicException("Count {$locked->id} is not locked.");
        }

        return $this->canonical($locked, $locked->chain_seq, $locked->locked_at, $locked->locked_by_user_id);
    }

    /**
     * @param  array<int, string>  $codes  reason code by reason id
     * @return array<string, mixed>
     */
    private function line(StockCountLine $line, array $codes): array
    {
        return [
            'item_id' => $line->item_id,
            'expected_qty' => CanonicalJson::quantity($line->expected_qty),
            'carried_qty' => CanonicalJson::quantity($line->carried_qty),
            'counted_qty' => CanonicalJson::quantity($line->counted_qty),
            'variance_qty' => CanonicalJson::quantity($line->variance_qty),
            'reason_code' => $line->variance_reason_id === null ? null : ($codes[$line->variance_reason_id] ?? null),
            'reason_note' => $line->variance_note,
            'adjustment_movement_id' => $line->adjustment_movement_id,
            'adjustment_qty' => $line->adjustment_movement_id === null ? null : CanonicalJson::quantity($line->variance_qty),
        ];
    }
}
