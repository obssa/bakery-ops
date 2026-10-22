<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\ShiftsFixture;

/*
 * The two snapshot race windows step 3.3a left to this step (phase-3 plan, 3.3a "As built", "Expected figure"), group concurrency.
 * A submit takes its expected figures from balance rows locked by Ledger::lock and records `ledger_movement_id`, the newest movement
 * at that instant, which `moved_since` and the opening-drift proof later measure "since the count" from.
 *
 *   Window 1 (closed): the id was read with a plain read, and under REPEATABLE READ a plain read sees the snapshot of the
 *   transaction's first plain read. A movement that committed on a locked pair after that snapshot but before the lock was inside
 *   `expected_qty` but not below `ledger_movement_id`, so moved_since counted it twice. The test holds a pair locked from another
 *   process until the submit is waiting for it, then lets a movement in: the id must cover that movement and
 *   expected + net change since the id must equal the ledger.
 *   Window 2 (harmless, proved): an item whose first stock arrives at the very instant of the submit is not on the count (the
 *   count cannot lock a pair it does not know). It changes no figure of the count; the ledger is right; the next count's draft
 *   lists the item with the live figure. The randomized rounds below check, whichever way the race falls, that for every line of
 *   the count expected + net change since `ledger_movement_id` equals the ledger now, that no engine deadlock happened, and that
 *   the late item is either refused as `incomplete` or simply left for the next count.
 * CommitsForConcurrency: the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('snapshotRaceDeadlocks')) {
    function snapshotRaceDeadlocks(): int
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

    /** A closing draft with its lines entered (flour 1000 on hand, counted 900), ready to hand in. */
    $this->round = function (int $n) {
        DB::table('shift_instances')->where('station_id', $this->f->kitchen->id)->whereNotNull('open_key')->update(['status' => 'locked', 'open_key' => null, 'locked_at' => now()]);
        DB::table('stock_counts')->whereIn('status', ['pending_approval', 'awaiting_cosign'])->update(['status' => 'approved']); // rule R-A: the last round's count is out of the way
        $flour = $this->f->ingredient();
        $this->f->stock($flour, $this->place, '1000');
        $shift = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => CarbonImmutable::parse('2026-01-01')->addDays($n)->toDateString()]);
        $count = (int) $this->f->close($this->f->chef, $shift->id)->assertOk()->json('counts.0.id');
        $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $flour->id, 'counted_qty' => '900', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();

        return [$count, $flour];
    };
    $this->submitJob = fn (int $count, int $delayMicros = 0): Closure => function () use ($count, $delayMicros): array {
        usleep($delayMicros);
        $response = $this->f->submit($this->f->chef, $count);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key')];
    };
    $this->receiveJob = fn (array $pairs, string $qty, int $holdMicros, int $delayMicros = 0): Closure => function () use ($pairs, $qty, $holdMicros, $delayMicros): array {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        usleep($delayMicros);
        DB::transaction(function () use ($pairs, $qty, $holdMicros): void {
            $ledger = app(Ledger::class);
            $ledger->lock(array_map(static fn (array $p): array => [$p[0], $p[1]], $pairs));
            usleep($holdMicros);
            $ledger->appendMany(array_map(
                static fn (array $p): MovementIntent => MovementIntent::of($p[0], $p[1], $qty, MovementReason::PurchaseIn, 'snapshot_race'),
                $pairs,
            ));
        }, 1);

        return ['received' => true];
    };
});

it('covers a movement that commits on a locked pair after the submit began: the id is not stale (window 1)', function () {
    [$count, $flour] = ($this->round)(1);

    // B holds the flour's balance row for 1.5 s and then writes +250; the submit starts 0.3 s later and waits for the row
    $results = Forks::together([
        ($this->receiveJob)([[$flour->id, $this->place->id]], '250', 1_500_000),
        ($this->submitJob)($count, 300_000),
    ]);

    foreach ($results as $i => $r) {
        expect($r['ok'] ?? false)->toBeTrue("process {$i}: ".($r['error'] ?? ''));
    }
    expect($results[1]['value']['status'])->toBe(200);
    $line = StockCount::query()->findOrFail($count)->lines()->sole();
    $movementId = (int) DB::table('stock_movements')->where('ref_type', 'snapshot_race')->value('id');
    $row = StockCount::query()->findOrFail($count);
    expect($line->expected_qty?->toString())->toBe('1250.0000', 'the submit waited for the row and read the figure after the movement')
        ->and($line->variance_qty?->toString())->toBe('-350.0000')
        ->and($row->ledger_movement_id)->toBeGreaterThanOrEqual($movementId, 'the id covers the movement that is inside expected')
        ->and(app(Stock::class)->netChangeSince($this->place->id, (int) $row->ledger_movement_id))->toBe([], 'nothing moved after the snapshot');
});

it('keeps expected plus the movement since the id equal to the ledger, and no deadlock, whichever way a racing receipt falls (30 rounds)', function () {
    $deadlocksBefore = snapshotRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        [$count, $flour] = ($this->round)(100 + $round);
        $late = $this->f->ingredient(); // its first stock arrives at the instant of the submit (window 2)
        $jitter = fn (): int => random_int(0, 60_000);
        $results = Forks::together([
            ($this->submitJob)($count, $jitter()),
            ($this->receiveJob)([[$flour->id, $this->place->id]], '25', 0, $jitter()),
            ($this->receiveJob)([[$late->id, $this->place->id]], '40', 0, $jitter()),
        ]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, process {$i}: ".($r['error'] ?? 'no result');
            }
        }
        $status = $results[0]['value']['status'] ?? 0;
        $key = $results[0]['value']['message_key'] ?? null;
        if ($status === 422 && $key === 'shifts.count.incomplete') {
            // the late item was seen: the counter adds it and hands in again
            $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $late->id, 'counted_qty' => '40', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
            $status = $this->f->submit($this->f->chef, $count)->getStatusCode();
        }
        if ($status !== 200) {
            $failures[] = "round {$round}: the submit answered {$status} ".json_encode($results[0]);

            continue;
        }

        $row = StockCount::query()->findOrFail($count);
        $moved = app(Stock::class)->netChangeSince($this->place->id, (int) $row->ledger_movement_id);
        foreach ($row->lines()->get() as $line) {
            $now = app(Stock::class)->of($line->item_id, $this->place->id)->onHand;
            $since = $moved[$line->item_id] ?? Quantity::zero();
            if (! $line->expected_qty->plus($since)->equals($now)) {
                $failures[] = "round {$round}: item {$line->item_id}: expected {$line->expected_qty} + moved since {$row->ledger_movement_id} ({$since}) is not {$now}";
            }
        }
        // the late item is on the count, or it is left to the next count with the right figure in the ledger
        $now = app(Stock::class)->of($late->id, $this->place->id)->onHand->toString();
        if ($now !== '40.0000') {
            $failures[] = "round {$round}: the late item holds {$now}, expected 40.0000";
        }
        if (! $row->lines()->where('item_id', $late->id)->exists() && DB::table('stock_count_lines')->where('item_id', $late->id)->exists()) {
            $failures[] = "round {$round}: the late item is on another count";
        }
    }

    expect($failures)->toBe([])->and(snapshotRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:shifts', 'feature');

it('leaves an item whose first stock came after the count to the next count, with the live figure (window 2)', function () {
    [$count, $flour] = ($this->round)(200);
    $this->f->submit($this->f->chef, $count)->assertOk();
    $late = $this->f->ingredient();
    $this->f->stock($late, $this->place, '40');
    $this->f->approve($this->f->admin, $count)->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    $lines = collect($start->json('counts.0.lines'))->keyBy('item.id');
    expect($lines->has($late->id))->toBeTrue('the next draft lists the item')
        ->and($lines[$late->id]['expected_qty'])->toBe('40.0000')
        ->and($lines[$late->id]['carried_qty'])->toBeNull()
        ->and($lines[$flour->id]['carried_qty'])->toBe('900.0000')
        ->and(DB::table('stock_count_lines')->where('count_id', $count)->where('item_id', $late->id)->exists())->toBeFalse();
    $this->f->lines($this->f->chef, $start->json('counts.0.id'), ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $start->json('counts.0.id'))->assertOk()->assertJsonPath('status', 'approved');
    expect(DB::table('audit_log')->where('action', 'count.opening_drift')->count())->toBe(0);
})->group('module:shifts', 'feature');
