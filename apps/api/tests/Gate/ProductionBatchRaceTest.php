<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\ProductionFixture;

/*
 * Step 2.7c, group concurrency (phase-2 plan section 7), through the whole stack in two processes on two connections. The
 * stock of a batch is written in one transaction that locks the request, then the batch, then the stock balances in the
 * ledger's ascending (item, location) order, so:
 *   - two confirms of one finished batch write one set of N + 1 movements, one audit row, and both answer 200 (the second
 *     finds it confirmed and writes nothing);
 *   - the baker pressing Done twice at the same moment, on a station without an overseer, writes one set too;
 *   - two batches that both need the last of one ingredient: exactly one is confirmed and the other is told the shortage, and
 *     the balance is exactly zero, never below;
 *   - two batches that use the same two ingredients in opposite recipe order never deadlock.
 * Every round uses new items, so the first movement of each (item, place) is what the children race for. The engine's
 * deadlock counter must not move. CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

const BATCH_RACE_ROUNDS = 15;

function batchRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    withActor($this->f->admin->id);
    config(['cache.default' => 'database']);

    $this->settings = fn () => DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
    // a product of the cake room made from the given ingredients (grams per 12 pieces), new for every round
    $this->product = function (array $lines): Item {
        $item = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create([
            'branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id, 'production_station_id' => $this->f->cakeRoomStation->id,
        ]);
        $this->f->recipe($item, '12', $lines);

        return $item;
    };
    $this->ingredient = fn (): Item => Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    // a finished batch of 12 of the product, waiting for an overseer
    $this->finished = function (Item $product) {
        $batch = $this->f->finishedBatch(null, [
            'recipe_id' => Recipe::query()->where('product_item_id', $product->id)->value('id'),
        ]);
        $batch->request->forceFill(['product_item_id' => $product->id])->save();

        return $batch;
    };
    $this->confirmAs = fn (int $batchId, $person): Closure => function () use ($batchId, $person): array {
        ($this->settings)();
        loginAs($person);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batchId}/confirm", []);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'confirmed_by' => $response->json('confirmed_by.id')];
    };
    $this->movementsOf = fn (int $batchId): int => DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batchId)->count();
});

it('writes one set of movements when two people confirm one batch at the same moment (15 rounds)', function () {
    $this->f->requireOverseer();
    $deadlocksBefore = batchRaceDeadlocks();
    $failures = [];
    foreach (range(1, BATCH_RACE_ROUNDS) as $round) {
        $flour = ($this->ingredient)();
        $sugar = ($this->ingredient)();
        $product = ($this->product)([[$flour, '1'], [$sugar, '1']]);
        stockIn($flour, $this->f->cakeRoom, '5000');
        stockIn($sugar, $this->f->cakeRoom, '5000');
        $batch = ($this->finished)($product);

        $results = Forks::together([($this->confirmAs)($batch->id, $this->f->manager), ($this->confirmAs)($batch->id, $this->f->admin)]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->all();
        $row = $batch->fresh();
        $audits = DB::table('audit_log')->where('action', 'production_batch.confirm')->where('subject_id', $batch->id)->count();
        if ($statuses !== [200, 200] || $row->status !== BatchStatus::Confirmed || ($this->movementsOf)($batch->id) !== 3 || $audits !== 1
            || stockRow($flour, $this->f->cakeRoom)->on_hand !== '4000.0000' || stockRow($product, $this->f->display)->on_hand !== '12.0000') {
            $failures[] = "round {$round}: ".json_encode($results).', '.($this->movementsOf)($batch->id)." movements, {$audits} audits";
        }
        $oracle = ledgerOracle($this->branch->id);
        if ($oracle['balances'] !== $oracle['movements'] || $oracle['movements'] !== $oracle['view']) {
            $failures[] = "round {$round}: the oracle disagrees";
        }
    }

    expect($failures)->toBe([])->and(batchRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');

it('writes one set of movements when the baker presses Done twice at the same moment on a station without an overseer (15 rounds)', function () {
    $deadlocksBefore = batchRaceDeadlocks();
    $failures = [];
    foreach (range(1, BATCH_RACE_ROUNDS) as $round) {
        $flour = ($this->ingredient)();
        $product = ($this->product)([[$flour, '1']]);
        stockIn($flour, $this->f->cakeRoom, '5000');
        $request = $this->f->request(['product_item_id' => $product->id, 'assigned_to_user_id' => $this->f->baker->id], RequestStatus::InProgress);
        $batch = ProductionBatch::factory()->create([
            'branch_id' => $this->branch->id, 'request_id' => $request->id, 'station_id' => $this->f->cakeRoomStation->id, 'recipe_id' => Recipe::query()->where('product_item_id', $product->id)->value('id'),
            'consume_location_id' => $this->f->cakeRoom->id, 'output_location_id' => $this->f->display->id,
            'started_by_user_id' => $this->f->baker->id, 'created_by_user_id' => $this->f->baker->id,
        ]);
        $press = fn (): Closure => function () use ($batch): array {
            ($this->settings)();
            loginAs($this->f->baker);
            $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batch->id}/done", ['produced_qty' => '10', 'waste_qty' => '2']);

            return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key')];
        };

        $results = Forks::together([$press(), $press()]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->all();
        $audits = DB::table('audit_log')->where('action', 'production_batch.done')->where('subject_id', $batch->id)->count();
        if ($statuses !== [200, 200] || $batch->fresh()->status !== BatchStatus::Confirmed || ($this->movementsOf)($batch->id) !== 2 || $audits !== 1
            || stockRow($flour, $this->f->cakeRoom)->on_hand !== '4000.0000') {
            $failures[] = "round {$round}: ".json_encode($results).', '.($this->movementsOf)($batch->id)." movements, {$audits} audits";
        }
    }

    expect($failures)->toBe([])->and(batchRaceDeadlocks())->toBe($deadlocksBefore);
})->group('concurrency', 'module:production', 'feature');

it('confirms one of two batches that need the last of an ingredient and tells the other the shortage (15 rounds)', function () {
    $this->f->requireOverseer();
    $deadlocksBefore = batchRaceDeadlocks();
    $failures = [];
    foreach (range(1, BATCH_RACE_ROUNDS) as $round) {
        $flour = ($this->ingredient)();
        $first = ($this->product)([[$flour, '1']]);
        $second = ($this->product)([[$flour, '1']]);
        stockIn($flour, $this->f->cakeRoom, '1000'); // exactly one batch of 12
        $a = ($this->finished)($first);
        $b = ($this->finished)($second);

        $results = Forks::together([($this->confirmAs)($a->id, $this->f->manager), ($this->confirmAs)($b->id, $this->f->admin)]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->sort()->values()->all();
        $keys = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['message_key'] : 'crashed')->filter()->values()->all();
        $confirmed = collect([$a, $b])->filter(fn ($x) => $x->fresh()->status === BatchStatus::Confirmed)->count();
        $written = ($this->movementsOf)($a->id) + ($this->movementsOf)($b->id);
        if ($statuses !== [200, 409] || $keys !== ['production.batch.ingredient_short'] || $confirmed !== 1 || $written !== 2
            || stockRow($flour, $this->f->cakeRoom)->on_hand !== '0.0000') {
            $failures[] = "round {$round}: ".json_encode($results).", {$confirmed} confirmed, {$written} movements";
        }
    }

    expect($failures)->toBe([])->and(batchRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');

it('never deadlocks two confirms that use the same two ingredients in opposite recipe order (15 rounds)', function () {
    $this->f->requireOverseer();
    $deadlocksBefore = batchRaceDeadlocks();
    $failures = [];
    foreach (range(1, BATCH_RACE_ROUNDS) as $round) {
        $x = ($this->ingredient)();
        $y = ($this->ingredient)();
        $xy = ($this->product)([[$x, '1'], [$y, '1']]);
        $yx = ($this->product)([[$y, '1'], [$x, '1']]);
        stockIn($x, $this->f->cakeRoom, '5000');
        stockIn($y, $this->f->cakeRoom, '5000');
        $a = ($this->finished)($xy);
        $b = ($this->finished)($yx);

        $results = Forks::together([($this->confirmAs)($a->id, $this->f->manager), ($this->confirmAs)($b->id, $this->f->admin)]);

        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->all();
        if ($statuses !== [200, 200] || stockRow($x, $this->f->cakeRoom)->on_hand !== '3000.0000' || stockRow($y, $this->f->cakeRoom)->on_hand !== '3000.0000') {
            $failures[] = "round {$round}: ".json_encode($results);
        }
    }

    expect($failures)->toBe([])->and(batchRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');

it('never takes reserved stock: a confirm racing a reservation of the last of an ingredient sees available under the lock (15 rounds)', function () {
    $this->f->requireOverseer();
    $deadlocksBefore = batchRaceDeadlocks();
    $failures = [];
    foreach (range(1, BATCH_RACE_ROUNDS) as $round) {
        $flour = ($this->ingredient)();
        $product = ($this->product)([[$flour, '1']]);
        stockIn($flour, $this->f->cakeRoom, '1000');
        $batch = ($this->finished)($product);
        $ref = (string) Str::uuid();
        $reserve = function () use ($flour, $ref): array {
            ($this->settings)();
            withActor($this->f->cashier->id);
            try {
                app(Reservations::class)->reserve([new ReserveLine($flour->id, '1000', $this->f->cakeRoom->id)], $ref);

                return ['status' => 200, 'message_key' => null];
            } catch (Throwable $e) {
                return ['status' => 409, 'message_key' => $e::class];
            }
        };

        $results = Forks::together([($this->confirmAs)($batch->id, $this->f->manager), $reserve]);

        $level = stockRow($flour, $this->f->cakeRoom);
        $confirmed = $batch->fresh()->status === BatchStatus::Confirmed;
        $consistent = $confirmed
            ? $level->on_hand === '0.0000' && $level->reserved === '0.0000'
            : $level->on_hand === '1000.0000' && $level->reserved === '1000.0000';
        $oracle = ledgerOracle($this->branch->id);
        if (! $consistent || $oracle['balances'] !== $oracle['movements'] || BigDecimal::of($level->on_hand)->isLessThan($level->reserved)) {
            $failures[] = "round {$round}: ".json_encode($results).", on_hand {$level->on_hand}, reserved {$level->reserved}";
        }
    }

    expect($failures)->toBe([])->and(batchRaceDeadlocks())->toBe($deadlocksBefore);
})->group('concurrency', 'module:production', 'feature');
