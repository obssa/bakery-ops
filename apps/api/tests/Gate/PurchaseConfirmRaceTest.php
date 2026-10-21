<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\Purchase;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\PurchaseFixture;

/*
 * Step 2.2b, group concurrency (phase-2 plan section 7, Concurrency: a purchase confirmed twice at once), through the
 * whole HTTP stack in two processes on two connections:
 *   - the same purchase confirmed twice at once under two Idempotency-Keys: the purchase row lock serialises them, both
 *     answer 200 with the same confirmed purchase, and exactly one set of purchase_in rows and one cost update exists;
 *   - two purchases of one NEW item confirmed at once, the first movement of that item at that place (the Ledger first-row
 *     race, fixed in this step): both succeed, the history is a chain and the stock is the sum;
 *   - two purchases naming two new items in opposite order: no deadlock, whatever order the lines are in;
 *   - the opening cost posted twice at once: one 201, one 409, one cost row.
 * CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

function purchaseRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = PurchaseFixture::build($this->branch);
    loginAs($this->f->admin);
    config(['cache.default' => 'database']); // the idempotency lock must be shared between the processes, as on the live host

    $this->newItem = fn (): Item => Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    $this->draft = fn (array $lines): int => (int) $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', $this->f->body($lines))->assertCreated()->json('id');
    // a job for Forks::together: confirm purchase $id as the admin and report what came back
    $this->confirmJob = fn (int $id): Closure => function () use ($id): array {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        loginAs($this->f->admin);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/confirm", []);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'purchase_status' => $response->json('status'), 'version' => $response->json('version')];
    };
});

it('receives once when the same purchase is confirmed twice at the same moment (10 rounds)', function () {
    $failures = [];
    foreach (range(1, 10) as $round) {
        $id = ($this->draft)([$this->f->line($this->f->flour, '10', '80'), $this->f->line($this->f->sugar, '5', '100')]);
        $results = Forks::together([($this->confirmJob)($id), ($this->confirmJob)($id)]);

        foreach ($results as $i => $r) {
            $value = $r['value'] ?? [];
            if (($r['ok'] ?? false) !== true || ($value['status'] ?? null) !== 200 || ($value['purchase_status'] ?? null) !== 'confirmed') {
                $failures[] = "round {$round}, process {$i}: ".json_encode($r);
            }
        }
        $movements = DB::table('stock_movements')->where('ref_type', 'purchase')->where('ref_id', $id)->count();
        $history = DB::table('item_cost_history')->whereIn('purchase_line_id', DB::table('purchase_lines')->where('purchase_id', $id)->pluck('id'))->count();
        $purchase = Purchase::query()->findOrFail($id);
        if ($movements !== 2 || $history !== 2 || $purchase->version !== 2 || $purchase->status->value !== 'confirmed') {
            $failures[] = "round {$round}: {$movements} movements, {$history} history rows, version {$purchase->version}, {$purchase->status->value}";
        }
    }
    $oracle = ledgerOracle($this->branch->id);

    expect($failures)->toBe([])->and($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:inventory', 'feature');

it('confirms two purchases of one new item at the same moment: both succeed, the history is a chain (10 rounds)', function () {
    $deadlocksBefore = purchaseRaceDeadlocks();
    $failures = [];
    foreach (range(1, 10) as $round) {
        $item = ($this->newItem)();
        $a = ($this->draft)([$this->f->line($item, '10', '80')]);
        $b = ($this->draft)([$this->f->line($item, '30', '100')]);

        $results = Forks::together([($this->confirmJob)($a), ($this->confirmJob)($b)]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 200) {
                $failures[] = "round {$round}, purchase {$i}: ".json_encode($r);
            }
        }
        $history = DB::table('item_cost_history')->where('item_id', $item->id)->orderBy('id')->get()->all();
        if (count($history) !== 2 || $history[1]->cost_before !== $history[0]->cost_after || bccomp($history[1]->qty_before, bcadd($history[0]->qty_before, $history[0]->qty_in, 4), 4) !== 0) {
            $failures[] = "round {$round}: history is not a chain: ".json_encode($history);
        }
        if (stockRow($item, $this->f->store)?->on_hand !== '40000.0000') {
            $failures[] = "round {$round}: the store holds ".json_encode(stockRow($item, $this->f->store));
        }
    }

    expect($failures)->toBe([])->and(purchaseRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('never deadlocks when two purchases of the same two new items name them in opposite order (10 rounds)', function () {
    $deadlocksBefore = purchaseRaceDeadlocks();
    $failures = [];
    foreach (range(1, 10) as $round) {
        $x = ($this->newItem)();
        $y = ($this->newItem)();
        $a = ($this->draft)([$this->f->line($x, '1', '10'), $this->f->line($y, '1', '20')]);
        $b = ($this->draft)([$this->f->line($y, '2', '30'), $this->f->line($x, '2', '40')]);

        $results = Forks::together([($this->confirmJob)($a), ($this->confirmJob)($b)]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 200) {
                $failures[] = "round {$round}, purchase {$i}: ".json_encode($r);
            }
        }
        foreach ([$x, $y] as $item) {
            if (DB::table('item_cost_history')->where('item_id', $item->id)->count() !== 2 || stockRow($item, $this->f->store)?->on_hand !== '3000.0000') {
                $failures[] = "round {$round}: item {$item->id} does not hold 3000 g after two chained updates";
            }
        }
    }

    expect($failures)->toBe([])->and(purchaseRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('states the opening cost once when it is posted twice at the same moment (10 rounds)', function () {
    $failures = [];
    foreach (range(1, 10) as $round) {
        $item = ($this->newItem)();
        $job = fn (): Closure => function () use ($item): array {
            DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
            loginAs($this->f->admin);
            $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/inventory/costs/{$item->id}/opening", ['unit_cost' => '80', 'unit_id' => $this->f->kg->id]);

            return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key')];
        };

        $results = Forks::together([$job(), $job()]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->sort()->values()->all();
        if ($statuses !== [201, 409]) {
            $failures[] = "round {$round}: ".json_encode($results);
        }
        if (DB::table('item_costs')->where('item_id', $item->id)->count() !== 1 || DB::table('item_cost_history')->where('item_id', $item->id)->count() !== 1) {
            $failures[] = "round {$round}: expected one cost row and one history row";
        }
    }

    expect($failures)->toBe([]);
})->group('concurrency', 'module:inventory', 'feature');
