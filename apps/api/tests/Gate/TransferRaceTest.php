<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\TransferFixture;

/*
 * Step 2.3, group concurrency (phase-2 plan sections 6 item 3, 7 and 10.4), through the whole HTTP stack in two
 * processes on two connections. The confirm locks the transfer row, then both balance rows through the ledger in its
 * fixed order, checks the source's `available` under those locks and writes both movements or none:
 *   - two different transfers racing for the last stock: one wins (200), the other is 409 inventory.transfer.insufficient
 *     with its row still `requested`; the store never goes below zero and exactly one pair of movements exists;
 *   - the same transfer confirmed twice at once: both answer 200 with the confirmed transfer, and there is ONE pair of rows;
 *   - a transfer into a place the item has never been at (no balance row yet), twice over, to the same place: the
 *     ledger's first-row fix keeps it deadlock-free (the engine's deadlock counter does not move);
 *   - two transfers in opposite directions between the same two places: the ascending lock order means no deadlock.
 * CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

const TRANSFER_RACE_ROUNDS = 30;

function transferRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = TransferFixture::build($this->branch);
    loginAs($this->f->manager);
    config(['cache.default' => 'database']); // the idempotency lock must be shared between the processes, as on the live host

    // a requested transfer as POST /transfers writes it (the actor stamp is the admin the fixture made current)
    $this->requested = fn ($item, $from, $to, string $qty): int => Transfer::factory()->create([
        'branch_id' => $this->branch->id, 'item_id' => $item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id,
        'qty' => $qty, 'created_by_user_id' => $this->f->baker->id, 'requested_by_user_id' => $this->f->baker->id,
    ])->id;
    // a job for Forks::together: confirm transfer $id as the manager and report what came back
    $this->confirmJob = fn (int $id): Closure => function () use ($id): array {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        loginAs($this->f->manager);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/transfers/{$id}/confirm", []);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'transfer_status' => $response->json('status'), 'version' => $response->json('version')];
    };
    $this->movementsOf = fn (int $id) => DB::table('stock_movements')->where('ref_type', 'transfer')->where('ref_id', $id)->count();
});

it('lets one of two transfers racing for the last stock win and refuses the other, which stays requested (30 rounds)', function () {
    $deadlocksBefore = transferRaceDeadlocks();
    $failures = [];
    foreach (range(1, TRANSFER_RACE_ROUNDS) as $round) {
        $item = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
        stockIn($item, $this->f->store, '5000');
        // 4 kg each from a 5 kg stock, to two places: the kitchen has never held this item, the cake room neither
        $a = ($this->requested)($item, $this->f->store, $this->f->kitchen, '4000');
        $b = ($this->requested)($item, $this->f->store, $this->f->cakeRoom, '4000');

        $results = Forks::together([($this->confirmJob)($a), ($this->confirmJob)($b)]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->sort()->values()->all();
        if ($statuses !== [200, 409]) {
            $failures[] = "round {$round}: ".json_encode($results);

            continue;
        }
        $loser = collect($results)->first(fn ($r) => $r['value']['status'] === 409);
        if (($loser['value']['message_key'] ?? null) !== 'inventory.transfer.insufficient') {
            $failures[] = "round {$round}: the loser answered ".json_encode($loser);
        }
        $rows = Transfer::query()->whereKey([$a, $b])->get()->map(fn (Transfer $t) => $t->status->value)->sort()->values()->all();
        if ($rows !== ['confirmed', 'requested']) {
            $failures[] = "round {$round}: statuses ".json_encode($rows);
        }
        if (stockRow($item, $this->f->store)?->on_hand !== '1000.0000' || ($this->movementsOf)($a) + ($this->movementsOf)($b) !== 2) {
            $failures[] = "round {$round}: the store holds ".json_encode(stockRow($item, $this->f->store)).' after '.(($this->movementsOf)($a) + ($this->movementsOf)($b)).' rows';
        }
    }
    $oracle = ledgerOracle($this->branch->id);

    expect($failures)->toBe([])
        ->and(DB::table('stock_balances')->where('on_hand', '<', 0)->count())->toBe(0)
        ->and($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view'])
        ->and(transferRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('writes one pair of rows when the same transfer is confirmed twice at the same moment (30 rounds)', function () {
    $failures = [];
    foreach (range(1, TRANSFER_RACE_ROUNDS) as $round) {
        $item = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
        stockIn($item, $this->f->store, '9000');
        $id = ($this->requested)($item, $this->f->store, $this->f->kitchen, '4000');

        $results = Forks::together([($this->confirmJob)($id), ($this->confirmJob)($id)]);

        foreach ($results as $i => $r) {
            $value = $r['value'] ?? [];
            if (($r['ok'] ?? false) !== true || ($value['status'] ?? null) !== 200 || ($value['transfer_status'] ?? null) !== 'confirmed') {
                $failures[] = "round {$round}, process {$i}: ".json_encode($r);
            }
        }
        $transfer = Transfer::query()->findOrFail($id);
        $audits = DB::table('audit_log')->where('action', 'transfer.confirm')->where('subject_id', $id)->count();
        if (($this->movementsOf)($id) !== 2 || $transfer->version !== 2 || $audits !== 1 || stockRow($item, $this->f->store)?->on_hand !== '5000.0000' || stockRow($item, $this->f->kitchen)?->on_hand !== '4000.0000') {
            $failures[] = "round {$round}: ".($this->movementsOf)($id)." rows, version {$transfer->version}, {$audits} audit rows, store ".json_encode(stockRow($item, $this->f->store)?->on_hand);
        }
    }
    $oracle = ledgerOracle($this->branch->id);

    expect($failures)->toBe([])->and($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:inventory', 'feature');

it('moves two transfers of one item into a place it has never been at, deadlock-free (30 rounds)', function () {
    $deadlocksBefore = transferRaceDeadlocks();
    $failures = [];
    foreach (range(1, TRANSFER_RACE_ROUNDS) as $round) {
        $item = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
        stockIn($item, $this->f->store, '9000');
        $a = ($this->requested)($item, $this->f->store, $this->f->kitchen, '2000');
        $b = ($this->requested)($item, $this->f->store, $this->f->kitchen, '3000');

        $results = Forks::together([($this->confirmJob)($a), ($this->confirmJob)($b)]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 200) {
                $failures[] = "round {$round}, transfer {$i}: ".json_encode($r);
            }
        }
        if (stockRow($item, $this->f->kitchen)?->on_hand !== '5000.0000' || stockRow($item, $this->f->store)?->on_hand !== '4000.0000') {
            $failures[] = "round {$round}: kitchen ".json_encode(stockRow($item, $this->f->kitchen)?->on_hand).', store '.json_encode(stockRow($item, $this->f->store)?->on_hand);
        }
    }

    expect($failures)->toBe([])->and(transferRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('never deadlocks when two transfers move the same item in opposite directions between the same two places (30 rounds)', function () {
    $deadlocksBefore = transferRaceDeadlocks();
    $failures = [];
    foreach (range(1, TRANSFER_RACE_ROUNDS) as $round) {
        $item = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
        stockIn($item, $this->f->store, '5000');
        stockIn($item, $this->f->cakeRoom, '5000');
        $out = ($this->requested)($item, $this->f->store, $this->f->cakeRoom, '1000');
        $back = ($this->requested)($item, $this->f->cakeRoom, $this->f->store, '2000');

        $results = Forks::together([($this->confirmJob)($out), ($this->confirmJob)($back)]);

        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 200) {
                $failures[] = "round {$round}, transfer {$i}: ".json_encode($r);
            }
        }
        if (stockRow($item, $this->f->store)?->on_hand !== '6000.0000' || stockRow($item, $this->f->cakeRoom)?->on_hand !== '4000.0000') {
            $failures[] = "round {$round}: store ".json_encode(stockRow($item, $this->f->store)?->on_hand).', cake room '.json_encode(stockRow($item, $this->f->cakeRoom)?->on_hand);
        }
    }
    $oracle = ledgerOracle($this->branch->id);

    expect($failures)->toBe([])
        ->and($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view'])
        ->and(transferRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');
