<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\ShiftsFixture;

/*
 * Count approval under real concurrency (step 3.3b; phase-3 plan section 4 and ADR 0013 rule 6, group concurrency). Each request runs
 * in its own process on its own connection through the whole HTTP stack. The lock order is the count row, the shift row, then the
 * balances in ascending (item_id, location_id) order, so none of these may deadlock (the Innodb_deadlocks counter is compared over
 * about 30 rounds each):
 *   - the same count approved twice at once (the admin and the manager): both answer 200, the adjustments are written once;
 *   - two counts of different places of one shift approved at once: both are approved, the shift is locked once, the adjustments
 *     are written once each;
 *   - a sale (a movement out of the same item and place) racing an approval: either order is allowed, but the ledger is never
 *     negative, an approval that wins is complete, and one that loses is refused whole (409 shifts.adjustment.insufficient).
 * CommitsForConcurrency: the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('approveRaceDeadlocks')) {
    function approveRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->bar = $this->f->place($this->f->kitchen, LocationType::Bar);

    /**
     * A fresh closing shift at the kitchen whose counts are handed in and waiting: flour at the kitchen place (1000, counted $counted)
     * and drink at the bar (500, counted 450). Returns the shift, the two counts and the items.
     */
    $this->round = function (int $n, string $counted = '700') {
        DB::table('shift_instances')->where('station_id', $this->f->kitchen->id)->whereNotNull('open_key')->update(['status' => 'locked', 'open_key' => null, 'locked_at' => now()]);
        $flour = $this->f->ingredient();
        $drink = $this->f->ingredient('ml');
        $this->f->stock($flour, $this->place, '1000');
        $this->f->stock($drink, $this->bar, '500');
        $shift = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => CarbonImmutable::parse('2026-01-01')->addDays($n)->toDateString()]);
        $counts = collect($this->f->close($this->f->chef, $shift->id)->assertOk()->json('counts'))->pluck('id', 'location.id');
        $this->f->lines($this->f->chef, $counts[$this->place->id], ['confirm_all' => true, 'lines' => [['item_id' => $flour->id, 'counted_qty' => $counted, 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
        $this->f->lines($this->f->chef, $counts[$this->bar->id], ['confirm_all' => true, 'lines' => [['item_id' => $drink->id, 'counted_qty' => '450', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
        $this->f->submit($this->f->chef, $counts[$this->place->id])->assertOk();
        $this->f->submit($this->f->chef, $counts[$this->bar->id])->assertOk();

        return [$shift, (int) $counts[$this->place->id], (int) $counts[$this->bar->id], $flour, $drink];
    };
    $this->approveJob = fn ($as, int $count): Closure => function () use ($as, $count): array {
        $response = $this->f->approve($as, $count);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'count_status' => $response->json('status'), 'decided_by' => $response->json('decided_by.id')];
    };
    $this->onHand = fn ($item, $place): string => app(Stock::class)->of($item, $place)->onHand->toString();
    $this->adjustments = fn (int $itemId): int => DB::table('stock_movements')->where('reason', 'count_adjustment')->where('item_id', $itemId)->count();
});

it('writes the adjustments once when the same count is approved twice at the same moment (30 rounds)', function () {
    $deadlocksBefore = approveRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        [$shift, $count, , $flour] = ($this->round)($round);
        $results = Forks::together([($this->approveJob)($this->f->admin, $count), ($this->approveJob)($this->f->manager, $count)]);

        foreach ($results as $i => $r) {
            $value = $r['value'] ?? [];
            if (($r['ok'] ?? false) !== true || ($value['status'] ?? null) !== 200 || ($value['count_status'] ?? null) !== 'approved') {
                $failures[] = "round {$round}, approver {$i}: ".json_encode($r);
            }
        }
        if (($results[0]['value']['decided_by'] ?? 1) !== ($results[1]['value']['decided_by'] ?? 2)) {
            $failures[] = "round {$round}: the two answers name different deciders ".json_encode($results);
        }
        if (($this->adjustments)($flour->id) !== 1 || ($this->onHand)($flour, $this->place) !== '700.0000') {
            $failures[] = "round {$round}: expected one adjustment and 700 on hand, got ".($this->adjustments)($flour->id).' and '.($this->onHand)($flour, $this->place);
        }
        if (DB::table('audit_log')->where('action', 'count.approve')->where('subject_id', $count)->count() !== 1) {
            $failures[] = "round {$round}: expected exactly one count.approve audit row";
        }
    }

    expect($failures)->toBe([])->and(approveRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:shifts', 'feature');

it('approves two counts of different places of one shift at the same moment, locks the shift once (30 rounds)', function () {
    $deadlocksBefore = approveRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        [$shift, $kitchenCount, $barCount, $flour, $drink] = ($this->round)($round);
        $results = Forks::together([($this->approveJob)($this->f->admin, $kitchenCount), ($this->approveJob)($this->f->manager, $barCount)]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 200) {
                $failures[] = "round {$round}, approver {$i}: ".json_encode($r);
            }
        }
        $row = ShiftInstance::query()->findOrFail($shift->id);
        if ($row->status->value !== 'locked' || $row->open_key !== null || $row->locked_at === null) {
            $failures[] = "round {$round}: the shift should be locked, is {$row->status->value}";
        }
        if (($this->onHand)($flour, $this->place) !== '700.0000' || ($this->onHand)($drink, $this->bar) !== '450.0000') {
            $failures[] = "round {$round}: wrong stock after the approvals";
        }
        if (StockCount::query()->where('shift_instance_id', $shift->id)->where('status', CountStatus::Approved->value)->count() !== 2) {
            $failures[] = "round {$round}: both counts should be approved";
        }
    }

    expect($failures)->toBe([])->and(approveRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:shifts', 'feature');

it('keeps the ledger right when a sale races an approval: either order, never negative, a loser is refused whole (30 rounds)', function () {
    $deadlocksBefore = approveRaceDeadlocks();
    $failures = [];
    $sell = fn (int $itemId, int $placeId, string $qty): Closure => function () use ($itemId, $placeId, $qty): array {
        try {
            DB::transaction(function () use ($itemId, $placeId, $qty): void {
                app(Ledger::class)->appendMany([MovementIntent::of($itemId, $placeId, '-'.$qty, MovementReason::Sale, 'test')]);
            }, 1);

            return ['sold' => true];
        } catch (Throwable $e) {
            return ['sold' => false, 'error' => $e::class];
        }
    };

    foreach (range(1, 30) as $round) {
        // 1000 on hand, the count found 700 (a delta of -300). A sale of 200 fits with the delta either way (500 left);
        // a sale of 800 fits with only one of them: the winner is complete and the loser is refused whole.
        $sale = $round % 2 === 1 ? '200' : '800';
        [$shift, $count, , $flour] = ($this->round)($round);
        $results = Forks::together([($this->approveJob)($this->f->admin, $count), $sell($flour->id, $this->place->id, $sale)]);

        $approve = $results[0]['value'] ?? ['status' => 0];
        $sold = ($results[1]['value']['sold'] ?? null) === true;
        $onHand = ($this->onHand)($flour, $this->place);
        $adjusted = ($this->adjustments)($flour->id);
        $status = StockCount::query()->findOrFail($count)->status;

        if (($results[0]['ok'] ?? false) !== true || ($results[1]['ok'] ?? false) !== true) {
            $failures[] = "round {$round}: a process failed ".json_encode($results);

            continue;
        }
        if ($approve['status'] === 200) {
            if ($adjusted !== 1 || $status !== CountStatus::Approved) {
                $failures[] = "round {$round}: an approved count must have its adjustment";
            }
            $expected = $sold ? (string) (700 - (int) $sale).'.0000' : '700.0000';
            if ($onHand !== $expected) {
                $failures[] = "round {$round}: approved, sold=".json_encode($sold).", on hand {$onHand}, expected {$expected}";
            }
        } elseif ($approve['status'] === 409 && $approve['message_key'] === 'shifts.adjustment.insufficient') {
            if ($adjusted !== 0 || $status !== CountStatus::PendingApproval || ! $sold || $onHand !== (string) (1000 - (int) $sale).'.0000') {
                $failures[] = "round {$round}: a refused approval must leave nothing behind (adjusted {$adjusted}, on hand {$onHand})";
            }
        } else {
            $failures[] = "round {$round}: unexpected approval answer ".json_encode($approve);
        }
        if ((float) $onHand < 0) {
            $failures[] = "round {$round}: negative stock {$onHand}";
        }
        if ($sale === '200' && ($approve['status'] !== 200 || ! $sold)) {
            $failures[] = "round {$round}: a sale of 200 and the approval both fit and both must succeed";
        }
        if ($sale === '800' && $approve['status'] === 200 && $sold) {
            $failures[] = "round {$round}: 800 sold and a -300 adjustment cannot both fit in 1000";
        }
    }

    expect($failures)->toBe([])->and(approveRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:shifts', 'feature');
