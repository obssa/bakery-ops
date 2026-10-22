<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Counts\CountLock;
use App\Modules\Shifts\Counts\DraftBuilder;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\SupersedeCause;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * POST /stock-counts/{count}/unlock (phase-3 plan section 4, "An unlock"; ADR 0012). The admin (shifts.unlock) gives a locked count a
 * revision, with a reason. One AuditedAction (count.unlock), the reason in the audit row and on the revision.
 *
 * The locked count is never rewritten: it stays locked, a link of the chain that still verifies, and gets `superseded_by_id` (the one
 * write the database lets a locked row take, Locks::supersede). The revision is a new draft of the same shift, place and type, `seq` + 1,
 * `supersede_cause = unlock`, whose lines copy the old counted figures, reasons and notes and are left unconfirmed, so the counter
 * must look at each again (a line for an item deleted from the catalog since is not copied). It is submitted and approved like any
 * count: its expected figure is the ledger under the balance locks at submit, which the first approval made equal to the old counted
 * figure (plus whatever moved since), so approving it writes only the correcting difference; when it locks it is the next link and
 * previousClosing carries it instead of the old row. The shift stays as it is (locked); the revision is taken by a locked shift
 * (SubmitCount::takesCount).
 *
 * Refused: 409 shifts.count.not_locked naming the status, 409 shifts.count.already_superseded naming the revision. Needs shifts.unlock
 * at the station (the admin).
 */
final class UnlockCount
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly ShiftAccess $access,
        private readonly ActorContext $actor,
        private readonly Locks $locks,
        private readonly Items $items,
    ) {}

    /**
     * @return array{0: StockCount, 1: StockCount} the unlocked count (still locked, now superseded) and its revision
     *
     * @throws ShiftError auth.forbidden, shifts.station.forbidden (403); shifts.count.not_locked, shifts.count.already_superseded (409)
     */
    public function handle(StockCount $count, string $reason): array
    {
        $person = $this->access->person('shifts.unlock');
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $this->access->assertAt($person, 'shifts.unlock', $station);
        $this->assertUnlockable($count);

        return $this->audit->run(function (StockCount $c) use ($reason): array {
            CountLock::refresh($c);
            $this->assertUnlockable($c);
            $now = CarbonImmutable::now()->startOfSecond();

            $revision = $this->makeRevision($c, $reason, $now);
            $this->locks->supersede($c, $revision, $now);

            return [$c, $revision];
        }, $count, 'count.unlock', ['reason' => $reason]);
    }

    /**
     * @throws ShiftError
     */
    private function assertUnlockable(StockCount $count): void
    {
        if ($count->status !== CountStatus::Locked) {
            throw ShiftError::countNotLocked($count->status->value);
        }
        if ($count->superseded_by_id !== null) {
            throw ShiftError::alreadySuperseded($count->superseded_by_id);
        }
    }

    private function makeRevision(StockCount $locked, string $reason, CarbonImmutable $now): StockCount
    {
        $seq = 1 + (int) StockCount::query()
            ->where('shift_instance_id', $locked->shift_instance_id)->where('location_id', $locked->location_id)->where('type', $locked->type->value)
            ->max('seq');
        $stamp = $this->actor->stamp();

        $revision = new StockCount;
        $revision->forceFill([
            'branch_id' => $locked->branch_id,
            'client_uuid' => DraftBuilder::clientUuid($locked->shift_instance_id, $locked->location_id, $locked->type, $seq),
            'shift_instance_id' => $locked->shift_instance_id,
            'location_id' => $locked->location_id,
            'type' => $locked->type,
            'seq' => $seq,
            'status' => CountStatus::Draft,
            'supersede_cause' => SupersedeCause::Unlock,
            'unlock_reason' => $reason,
            'started_at' => $now,
            'created_by_user_id' => $stamp['created_by_user_id'],
            'acting_user_id' => $stamp['acting_user_id'],
            'actor_station_id' => $stamp['station_id'],
            'device_id' => $stamp['device_id'],
            'version' => 1,
        ])->save();

        $lines = StockCountLine::query()->where('count_id', $locked->id)->orderBy('item_id')->get();
        $gone = array_flip($this->items->deleted(array_values($lines->pluck('item_id')->map(static fn (mixed $id): int => (int) $id)->all())));
        $rows = [];
        foreach ($lines as $line) {
            if (isset($gone[$line->item_id])) {
                continue;
            }
            $rows[] = [
                'branch_id' => $locked->branch_id,
                'count_id' => $revision->id,
                'item_id' => $line->item_id,
                'carried_qty' => $line->carried_qty?->toString(),
                'counted_qty' => $line->counted_qty?->toString(),
                'confirmed_at' => null,
                'variance_reason_id' => $line->variance_reason_id,
                'variance_note' => $line->variance_note,
                'reason_status' => 'none',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('stock_count_lines')->insert($rows);
        }

        return $revision;
    }
}
