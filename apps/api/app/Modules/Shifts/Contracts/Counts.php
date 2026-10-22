<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Shifts\Actions\AlreadyReached;
use App\Modules\Shifts\Actions\SetCountLines;
use App\Modules\Shifts\Actions\SubmitCount;
use App\Modules\Shifts\Counts\CountReads;
use App\Modules\Shifts\Counts\DraftBuilder;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Models\Station;

/**
 * manifest shifts.provides, as the plan splits it (phase-3 plan section 6 item 3, step 3.3a): draftsFor(shift, type), setLines and
 * submit. The endpoints (PUT /stock-counts/{count}/lines, POST /stock-counts/{count}/submit) are thin callers of the same actions
 * (Actions\SetCountLines, Actions\SubmitCount), so every rule is one rule, and every method is one AuditedAction run as the person
 * acting (ActorContext) under the same permissions and the same errors as its endpoint.
 *   draftsFor: the draft counts of a shift boundary, one per stock place of the shift's station (never the cash drawer), made once
 *     (a repeat answers the rows already there and writes nothing). The lines are the items with stock at the place, the items of the
 *     place's previous count, and for an opening count the carried figure of the latest approved closing (DraftBuilder).
 *   setLines: the counter touches the lines of a draft: figures, confirmations, reasons, notes, items found; `confirmAll` is the
 *     explicit "nothing changed".
 *   submit: hands the count in. The expected figure comes from the balance rows read under Ledger::lock at the snapshot; the
 *     variance is counted minus expected in exact decimals; a count with a variance waits in pending_approval, one without goes to
 *     approved; the shift moves on with it. A count already handed in answers itself.
 * Counts and shifts are given by model or id. A final class resolved per use: the actions carry the scoped ActorContext and
 * CurrentBranch, so it keeps nothing.
 */
final class Counts
{
    public function __construct(
        private readonly DraftBuilder $drafts,
        private readonly CountReads $reads,
        private readonly SetCountLines $setLines,
        private readonly SubmitCount $submitCount,
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
    ) {}

    /**
     * @return list<StockCount> ascending by place
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403)
     */
    public function draftsFor(ShiftInstance|int $shift, CountType $type): array
    {
        $shift = $shift instanceof ShiftInstance ? $shift : ShiftInstance::query()->findOrFail($shift);
        $person = $this->access->person('shifts.count');
        if (! $this->access->mayManage($person)) {
            $this->access->assertAt($person, 'shifts.count', Station::query()->withTrashed()->findOrFail($shift->station_id));
        }

        try {
            return $this->audit->run(function (ShiftInstance $s) use ($type): array {
                [$counts, $created] = $this->drafts->build($s, $type);
                if (! $created) {
                    throw AlreadyReached::here();
                }

                return $counts;
            }, $shift, 'count.drafts');
        } catch (AlreadyReached) {
            return $this->reads->latest($shift, $type);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines  [{item_id, counted_qty?, confirm?, reason_id?, reason_note?}]
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_draft (409);
     *                    shifts.count.item_unknown, shifts.count.qty_invalid, shifts.reason.unknown (422)
     */
    public function setLines(StockCount|int $count, array $lines, bool $confirmAll = false): StockCount
    {
        return $this->setLines->handle($this->count($count), $lines, $confirmAll);
    }

    /**
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_draft, shifts.count.shift_not_ready,
     *                    shifts.count.blocked_by_pending (409); shifts.count.incomplete, shifts.count.reason_required,
     *                    shifts.count.note_required (422)
     */
    public function submit(StockCount|int $count): StockCount
    {
        return $this->submitCount->handle($this->count($count));
    }

    private function count(StockCount|int $count): StockCount
    {
        return $count instanceof StockCount ? $count : StockCount::query()->findOrFail($count);
    }
}
