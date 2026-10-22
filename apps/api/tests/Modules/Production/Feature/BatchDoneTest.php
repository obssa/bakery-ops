<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Notifications\Listeners\NotifyBatchDone;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Events\BatchDone;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/batches/{batch}/done (step 2.7c; phase-2 plan section 4, "How a batch consumes and produces"). The baker
 * who started the batch says how many were made and how many were wasted.
 *   - Where the station needs no overseer, Done is the whole of it: the batch is finished AND confirmed in one write
 *     (confirmed_at = finished_at, the one case the sign-off CHECK lets the same person confirm), the request is done, and one
 *     Ledger::appendMany writes N production_consume rows (the recipe version recorded at start, scaled to produced plus
 *     waste, at the consume place) and one production_output row (produced, at the output place), all or nothing.
 *   - Where it does, the batch is finished, the request pending_confirmation, and nothing is written yet.
 *   - An ingredient that is short (available = on hand minus reserved) is 409 with the shortfall and writes nothing.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom();
    loginAs($this->f->baker);
    $this->done = fn (ProductionBatch $batch, string $produced = '12', string $waste = '0', ?array $headers = null) => $this->withHeaders($headers ?? idempotencyKey())
        ->postJson("/api/v1/production/batches/{$batch->id}/done", ['produced_qty' => $produced, 'waste_qty' => $waste]);
    $this->movements = fn () => DB::table('stock_movements')->where('ref_type', 'production_batch')->orderBy('id')->get();
    $this->audits = fn (ProductionBatch $batch) => AuditLog::query()->where('action', 'production_batch.done')->where('subject_id', $batch->id);
});

it('finishes and confirms in one write where the station needs no overseer: N consume rows and one output row, scaled to produced plus waste', function () {
    $batch = $this->f->startedBatch();
    $batch->request->forceFill(['reason' => 'stock_out', 'open_stock_out_key' => 1])->save();

    $response = ($this->done)($batch, '10', '2')->assertOk();

    $response->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('produced_qty', '10.0000')
        ->assertJsonPath('waste_qty', '2.0000')
        ->assertJsonPath('request.status', 'done')
        ->assertJsonPath('confirmed_by.id', $this->f->baker->id)
        ->assertJsonPath('finished_by.id', $this->f->baker->id);
    $rows = ($this->movements)();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('reason')->all())->toBe(['production_consume', 'production_consume', 'production_output'])
        ->and($rows->pluck('ref_id')->unique()->all())->toBe([$batch->id])
        ->and(Uuid::isValid((string) $rows[0]->client_uuid))->toBeTrue();
    $byItem = $rows->keyBy('item_id');
    expect($byItem[$this->f->flour->id]->qty)->toBe('-1200.0000', '1.2 kg of flour for 12 cakes, wasted ones included')
        ->and($byItem[$this->f->flour->id]->from_location_id)->toBe($this->f->cakeRoom->id)
        ->and($byItem[$this->f->sugar->id]->qty)->toBe('-600.0000')
        ->and($byItem[$this->f->cake->id]->qty)->toBe('10.0000', 'only the produced units enter stock')
        ->and($byItem[$this->f->cake->id]->to_location_id)->toBe($this->f->display->id);

    $row = $batch->fresh();
    expect($row->status)->toBe(BatchStatus::Confirmed)
        ->and($row->confirmed_by_user_id)->toBe($this->f->baker->id)
        ->and($row->confirmed_at->equalTo($row->finished_at))->toBeTrue('the sign-off CHECK: the same instant')
        ->and($row->output_movement_id)->toBe($byItem[$this->f->cake->id]->id)
        ->and((string) $row->produced_qty)->toBe('10.0000')
        ->and($row->version)->toBe(2);
    $request = $batch->request->fresh();
    expect($request->status)->toBe(RequestStatus::Done)
        ->and($request->done_at)->not->toBeNull()
        ->and($request->open_stock_out_key)->toBeNull('the next run-out may ask again');
    expect(stockRow($this->f->flour, $this->f->cakeRoom)->on_hand)->toBe('3800.0000')
        ->and(stockRow($this->f->cake, $this->f->display)->on_hand)->toBe('10.0000');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['balances'])->toBe($oracle['movements'])->toBe($oracle['view']);
    $audit = ($this->audits)($batch)->sole();
    expect($audit->actor_id)->toBe($this->f->baker->id)->and($audit->before['status'])->toBe('started')->and($audit->after['status'])->toBe('confirmed');
})->group('gate:p2');

it('stamps the rows with the baker and derives their client uuids from the batch', function () {
    $batch = $this->f->startedBatch();
    ($this->done)($batch)->assertOk();

    $rows = ($this->movements)();
    expect($rows->pluck('user_id')->unique()->all())->toBe([$this->f->baker->id]);
    $expected = collect([0, 1])->map(fn (int $i) => Uuid::uuid5(Uuid::NAMESPACE_URL, "production-batch:{$this->branch->id}:{$batch->client_uuid}:consume:{$i}")->toString())
        ->push(Uuid::uuid5(Uuid::NAMESPACE_URL, "production-batch:{$this->branch->id}:{$batch->client_uuid}:output")->toString())->all();
    expect($rows->pluck('client_uuid')->all())->toBe($expected);
});

it('scales to four places: a yield of 7, 4 made and 1 wasted takes 714.2857 g of flour', function () {
    $loaf = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create([
        'branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id, 'production_station_id' => $this->f->cakeRoomStation->id,
    ]);
    $recipe = $this->f->recipe($loaf, '7', [[$this->f->flour, '1']]);
    $batch = $this->f->startedBatch(null, '7', ['recipe_id' => $recipe->id]);
    $batch->request->forceFill(['product_item_id' => $loaf->id])->save();

    ($this->done)($batch, '4', '1')->assertOk();

    $flour = ($this->movements)()->firstWhere('item_id', $this->f->flour->id);
    expect($flour->qty)->toBe('-714.2857')
        ->and(stockRow($this->f->flour, $this->f->cakeRoom)->on_hand)->toBe('4285.7143');
})->group('gate:p2');

it('consumes the version recorded at start even when a newer one was published', function () {
    $batch = $this->f->startedBatch();
    $this->f->cakeRecipe->forceFill(['is_active' => false])->save();
    $this->f->recipe($this->f->cake, '12', [[$this->f->flour, '3']], [], 2);

    ($this->done)($batch)->assertOk();

    $rows = ($this->movements)()->keyBy('item_id');
    expect($rows[$this->f->flour->id]->qty)->toBe('-1200.0000')->and($rows[$this->f->sugar->id]->qty)->toBe('-600.0000');
});

it('writes no output row when everything made was wasted, and still takes the ingredients', function () {
    $batch = $this->f->startedBatch();

    ($this->done)($batch, '0', '12')->assertOk()->assertJsonPath('status', 'confirmed');

    expect(($this->movements)()->pluck('reason')->all())->toBe(['production_consume', 'production_consume'])
        ->and($batch->fresh()->output_movement_id)->toBeNull()
        ->and(stockRow($this->f->cake, $this->f->display))->toBeNull();
});

it('leaves the batch finished and the request pending, writing nothing, where the station needs an overseer', function () {
    $this->f->requireOverseer();
    $batch = $this->f->startedBatch();

    $response = ($this->done)($batch, '12', '1')->assertOk();

    $response->assertJsonPath('status', 'finished')->assertJsonPath('request.status', 'pending_confirmation')
        ->assertJsonPath('finished_by.id', $this->f->baker->id)->assertJsonPath('confirmed_by', null);
    $row = $batch->fresh();
    expect($row->status)->toBe(BatchStatus::Finished)
        ->and($row->confirmed_at)->toBeNull()
        ->and($row->finished_at)->not->toBeNull()
        ->and((string) $row->waste_qty)->toBe('1.0000')
        ->and($row->request->fresh()->status)->toBe(RequestStatus::PendingConfirmation)
        ->and(($this->movements)())->toHaveCount(0)
        ->and(stockRow($this->f->flour, $this->f->cakeRoom)->on_hand)->toBe('5000.0000');
});

it('does not check stock at Done where an overseer signs off later: the shortage is found at confirm', function () {
    $this->f->requireOverseer();
    $short = $this->f->startedBatch();
    DB::table('stock_balances')->where('item_id', $this->f->flour->id)->update(['on_hand' => '10.0000']);

    ($this->done)($short)->assertOk()->assertJsonPath('status', 'finished');
});

it('answers 409 with the shortfall and writes nothing when an ingredient is short, in both languages', function () {
    $batch = $this->f->startedBatch();
    DB::table('stock_balances')->where('item_id', $this->f->flour->id)->update(['on_hand' => '1500.0000']);
    app(Reservations::class)->reserve([new ReserveLine($this->f->flour->id, '600', $this->f->cakeRoom->id)], (string) Str::uuid());
    $movements = DB::table('stock_movements')->count();
    $oracle = ledgerOracle($this->branch->id);
    $audits = AuditLog::query()->count();

    $response = ($this->done)($batch)->assertStatus(409);

    $response->assertJsonPath('message_key', 'production.batch.ingredient_short')
        ->assertJsonPath('shortfall.0.ingredient.id', $this->f->flour->id)
        ->assertJsonPath('shortfall.0.ingredient.name_en', 'Flour')
        ->assertJsonPath('shortfall.0.ingredient.name_am', 'ዱቄት')
        ->assertJsonPath('shortfall.0.needed', '1200.0000')
        ->assertJsonPath('shortfall.0.available', '900.0000')
        ->assertJsonPath('shortfall.0.short', '300.0000')
        ->assertJsonPath('shortfall.0.location_id', $this->f->cakeRoom->id)
        ->assertJsonCount(1, 'shortfall');
    expect($response->json('message_en'))->toContain('Flour')->toContain('300')->toContain('Cake room')
        ->and($response->json('message_am'))->toContain('ዱቄት')->toContain('300')->toMatch('/\p{Ethiopic}/u');
    expect(DB::table('stock_movements')->count())->toBe($movements, 'the reservation row of the setup is the last one')
        ->and(ledgerOracle($this->branch->id))->toBe($oracle)
        ->and($batch->fresh()->status)->toBe(BatchStatus::Started)
        ->and($batch->fresh()->version)->toBe(1)
        ->and($batch->request->fresh()->status)->toBe(RequestStatus::InProgress)
        ->and(AuditLog::query()->count())->toBe($audits)
        ->and(stockRow($this->f->sugar, $this->f->cakeRoom)->on_hand)->toBe('5000.0000');
});

it('lists every short ingredient: stock at zero is refused, negative stock stays refused', function () {
    $batch = $this->f->startedBatch();
    DB::table('stock_balances')->whereIn('item_id', [$this->f->flour->id, $this->f->sugar->id])->update(['on_hand' => '0.0000']);

    $response = ($this->done)($batch)->assertStatus(409)->assertJsonCount(2, 'shortfall');

    expect(collect($response->json('shortfall'))->pluck('short')->sort()->values()->all())->toBe(['600.0000', '1200.0000'])
        ->and(collect($response->json('shortfall'))->pluck('available')->unique()->all())->toBe(['0.0000'])
        ->and($batch->fresh()->status)->toBe(BatchStatus::Started);
});

it('lets the baker retry after a transfer and then finish', function () {
    $batch = $this->f->startedBatch();
    DB::table('stock_balances')->where('item_id', $this->f->sugar->id)->update(['on_hand' => '100.0000']);
    ($this->done)($batch)->assertStatus(409);

    DB::table('stock_balances')->where('item_id', $this->f->sugar->id)->update(['on_hand' => '600.0000']);
    ($this->done)($batch)->assertOk()->assertJsonPath('status', 'confirmed');
    expect(($this->movements)())->toHaveCount(3);
});

it('answers a repeat with the same numbers 200 and writes nothing; other numbers on a settled batch are 409', function () {
    $batch = $this->f->startedBatch();
    ($this->done)($batch, '10', '2')->assertOk();
    $movements = DB::table('stock_movements')->count();
    $audits = AuditLog::query()->count();

    ($this->done)($batch, '10', '2')->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('version', 2);
    expect(DB::table('stock_movements')->count())->toBe($movements)->and(AuditLog::query()->count())->toBe($audits);

    ($this->done)($batch, '11', '1')->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'confirmed')
        ->assertJsonPath('to', 'finished');
    expect(DB::table('stock_movements')->count())->toBe($movements);
});

it('replays the same Idempotency-Key with the same answer', function () {
    $batch = $this->f->startedBatch();
    $headers = idempotencyKey();

    $first = ($this->done)($batch, '12', '0', $headers)->assertOk();
    $second = ($this->done)($batch, '12', '0', $headers)->assertOk();

    expect($second->json())->toBe($first->json())->and(($this->movements)())->toHaveCount(3);
});

it('validates the quantities: decimal strings, none negative, the sum above zero, at most four places', function () {
    $batch = $this->f->startedBatch();
    $send = fn (array $body) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batch->id}/done", $body);

    foreach ([
        [],
        ['produced_qty' => '10'],
        ['produced_qty' => '0', 'waste_qty' => '0'],
        ['produced_qty' => '-1', 'waste_qty' => '2'],
        ['produced_qty' => '1.00001', 'waste_qty' => '0'],
        ['produced_qty' => 1.5, 'waste_qty' => '0'],
        ['produced_qty' => 'abc', 'waste_qty' => '0'],
        ['produced_qty' => '1', 'waste_qty' => null],
    ] as $body) {
        $send($body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
    expect($batch->fresh()->status)->toBe(BatchStatus::Started)->and(($this->movements)())->toHaveCount(0);
});

it('is the assignee who presses Done, at the station', function () {
    $batch = $this->f->startedBatch($this->f->baker2);

    ($this->done)($batch)->assertStatus(409)->assertJsonPath('message_key', 'production.request.taken');
    loginAs($this->f->breadBaker);
    ($this->done)($batch)->assertStatus(403)->assertJsonPath('message_key', 'production.station.forbidden');
    foreach ([$this->f->manager, $this->f->cashier, $this->f->chef, $this->f->waiter] as $person) {
        loginAs($person);
        ($this->done)($batch)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect($batch->fresh()->status)->toBe(BatchStatus::Started)->and(($this->movements)())->toHaveCount(0);

    loginAs($this->f->baker2);
    ($this->done)($batch)->assertOk();
});

it('refuses a finished batch that is past it, and another branch batch is 404', function () {
    $this->f->requireOverseer();
    $pending = $this->f->finishedBatch();
    ($this->done)($pending, '5', '5')->assertStatus(409)->assertJsonPath('message_key', 'state_machine.illegal_transition');

    $foreign = ProductionBatch::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->done)($foreign)->assertStatus(404);
});

it('announces BatchDone after commit: confirmed on the combined path, pending where an overseer must sign', function () {
    Event::fake([BatchDone::class]);
    $batch = $this->f->startedBatch();
    ($this->done)($batch, '10', '2')->assertOk();

    Event::assertDispatched(BatchDone::class, fn ($e) => $e->status === 'confirmed' && $e->batchId === $batch->id && $e->producedQty === '10.0000' && $e->wasteQty === '2.0000'
        && $e->productNameEn === 'Black forest cake' && $e->stationId === $this->f->cakeRoomStation->id && $e->finishedByUserId === $this->f->baker->id);

    $this->f->requireOverseer();
    $second = $this->f->startedBatch();
    ($this->done)($second)->assertOk();
    Event::assertDispatched(BatchDone::class, fn ($e) => $e->status === 'pending_confirmation' && $e->batchId === $second->id);
});

it('announces nothing when the write is refused', function () {
    Event::fake([BatchDone::class]);
    $batch = $this->f->startedBatch();
    DB::table('stock_balances')->where('item_id', $this->f->flour->id)->update(['on_hand' => '1.0000']);

    ($this->done)($batch)->assertStatus(409);

    Event::assertNotDispatched(BatchDone::class);
});

it('queues nothing but the stock evaluations and the notification', function () {
    Queue::fake();
    $this->f->requireOverseer();
    ($this->done)($this->f->startedBatch())->assertOk();

    assertNothingQueuedButStockEvaluation([NotifyBatchDone::class]);
});
