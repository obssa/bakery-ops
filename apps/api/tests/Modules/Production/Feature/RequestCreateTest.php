<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Notifications\Listeners\NotifyProductionRequested;
use App\Modules\Production\Contracts\Requests;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Events\ProductionRequested;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/requests and Requests::create (step 2.7b; phase-2 plan sections 4, 5 and 10). Anyone who may ask for
 * production (production.request) asks for a to-stock product that has an active recipe and a producing station; the
 * quantity defaults to the product batch size (Items::batchQty), a due time or a priority other than normal is the planner's
 * (production.plan). A manual request for a product that already has an open one is refused, naming it; scheduled ones may
 * stack. The client UUID makes an offline replay answer the same request. Nothing is written to the ledger.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->cashier);
    $this->post = fn (array $body) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/production/requests', $body);
});

it('writes a requested manual request for the batch size at the producing station, audited, and no movement', function () {
    Event::fake([ProductionRequested::class]);
    $movements = DB::table('stock_movements')->count();

    $response = ($this->post)($this->f->body($this->f->cake, ['note' => 'for the wedding']));

    $response->assertCreated()
        ->assertJsonPath('status', 'requested')
        ->assertJsonPath('reason', 'manual')
        ->assertJsonPath('priority', 'normal')
        ->assertJsonPath('product.id', $this->f->cake->id)
        ->assertJsonPath('product.name_en', 'Black forest cake')
        ->assertJsonPath('product.name_am', 'ብላክ ፎረስት ኬክ')
        ->assertJsonPath('product.unit', 'pcs')
        ->assertJsonPath('requested_qty', '12.0000')
        ->assertJsonPath('due_at', null)
        ->assertJsonPath('station.id', $this->f->cakeRoomStation->id)
        ->assertJsonPath('station.code', 'cake_room')
        ->assertJsonPath('station.requires_overseer', false)
        ->assertJsonPath('requested_by.id', $this->f->cashier->id)
        ->assertJsonPath('requested_by.name', $this->f->cashier->name)
        ->assertJsonPath('assigned_to', null)
        ->assertJsonPath('batch', null)
        ->assertJsonPath('note', 'for the wedding')
        ->assertJsonPath('version', 1);
    expect($response->json('created_at'))->not->toBeNull();

    $row = ProductionRequest::query()->findOrFail($response->json('id'));
    expect($row->status)->toBe(RequestStatus::Requested)
        ->and($row->reason)->toBe(RequestReason::Manual)
        ->and($row->open_stock_out_key)->toBeNull()
        ->and($row->source_alert_id)->toBeNull()
        ->and($row->created_by_user_id)->toBe($this->f->cashier->id)
        ->and($row->requested_by_user_id)->toBe($this->f->cashier->id)
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'a request moves nothing')
        ->and(AuditLog::query()->where('action', 'production_request.create')->where('subject_id', $row->id)->where('actor_id', $this->f->cashier->id)->exists())->toBeTrue();

    Event::assertDispatchedTimes(ProductionRequested::class, 1);
    $event = Event::dispatched(ProductionRequested::class)->first()[0];
    expect($event->branchId)->toBe($this->branch->id)
        ->and($event->payload())->toMatchArray([
            'request_id' => $row->id,
            'product_item_id' => $this->f->cake->id,
            'product_name_en' => 'Black forest cake',
            'product_name_am' => 'ብላክ ፎረስት ኬክ',
            'qty' => '12.0000',
            'unit' => 'pcs',
            'station_id' => $this->f->cakeRoomStation->id,
            'station_name_en' => $this->f->cakeRoomStation->name_en,
            'station_name_am' => $this->f->cakeRoomStation->name_am,
            'reason' => 'manual',
            'priority' => 'normal',
            'due_at' => null,
            'requested_by_user_id' => $this->f->cashier->id,
        ]);
});

it('asks for the stored batch size when the product has one, else the recipe yield, and takes a quantity in the base unit', function () {
    $bread = ($this->post)($this->f->body($this->f->bread))->assertCreated();
    $custom = ($this->post)($this->f->body($this->f->cake, ['qty' => '30']))->assertCreated();
    loginAs($this->f->chef);
    $fraction = ($this->post)($this->f->body($this->f->plainCake, ['qty' => '2.5']));

    expect($bread->json('requested_qty'))->toBe('40.0000')
        ->and($bread->json('station.id'))->toBe($this->f->bakeryStation->id)
        ->and($custom->json('requested_qty'))->toBe('30.0000');
    $fraction->assertStatus(422)->assertJsonPath('message_key', 'production.product.not_producible'); // no recipe: it cannot be scaled
});

it('answers a replay of the same client_uuid with the same request, 200, writing and announcing nothing', function () {
    Event::fake([ProductionRequested::class]);
    $body = $this->f->body($this->f->cake);
    $first = ($this->post)($body)->assertCreated();
    $audits = AuditLog::query()->count();

    $second = ($this->post)($body)->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(ProductionRequest::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);
    Event::assertDispatchedTimes(ProductionRequested::class, 1);
});

it('refuses a second manual request for a product that has an open one, naming it, until that one is closed', function () {
    $first = ($this->post)($this->f->body($this->f->cake))->assertCreated();

    $second = ($this->post)($this->f->body($this->f->cake));
    $second->assertStatus(409)
        ->assertJsonPath('message_key', 'production.request.already_open')
        ->assertJsonPath('request_id', $first->json('id'));
    expect($second->json('message_en'))->not->toBe('production.request.already_open')
        ->and($second->json('message_am'))->toMatch('/\p{Ethiopic}/u')
        ->and(ProductionRequest::query()->count())->toBe(1);

    ($this->post)($this->f->body($this->f->bread))->assertCreated(); // another product is not blocked

    ProductionRequest::query()->whereKey($first->json('id'))->update(['status' => 'cancelled', 'cancelled_at' => now(), 'version' => 2]);
    ($this->post)($this->f->body($this->f->cake))->assertCreated();
});

it('counts every open request of the product, an automatic one and a scheduled one too, against a manual request', function () {
    $this->f->request([], null, true);
    ($this->post)($this->f->body($this->f->cake))->assertStatus(409)->assertJsonPath('message_key', 'production.request.already_open');

    ProductionRequest::query()->delete();
    $this->f->request(['due_at' => now()->addDay(), 'reason' => 'schedule']);
    ($this->post)($this->f->body($this->f->cake))->assertStatus(409)->assertJsonPath('message_key', 'production.request.already_open');
});

it('lets only a planner ask for a due time or a priority other than normal', function () {
    $due = CarbonImmutable::now()->addDay()->startOfSecond();

    foreach ([['due_at' => $due->toIso8601String()], ['priority' => 'high'], ['priority' => 'urgent']] as $planning) {
        ($this->post)($this->f->body($this->f->cake, $planning))
            ->assertStatus(403)->assertJsonPath('message_key', 'production.plan.required');
    }
    expect(ProductionRequest::query()->count())->toBe(0);

    loginAs($this->f->manager);
    $scheduled = ($this->post)($this->f->body($this->f->cake, ['due_at' => $due->toIso8601String(), 'priority' => 'high']))->assertCreated();
    expect($scheduled->json('reason'))->toBe('schedule')
        ->and($scheduled->json('priority'))->toBe('high')
        ->and(CarbonImmutable::parse($scheduled->json('due_at'))->equalTo($due))->toBeTrue();

    $urgent = ($this->post)($this->f->body($this->f->bread, ['priority' => 'urgent']))->assertCreated();
    expect($urgent->json('reason'))->toBe('manual')->and($urgent->json('priority'))->toBe('urgent');
});

it('lets scheduled requests for the same product stack', function () {
    loginAs($this->f->manager);
    $due = CarbonImmutable::now()->addDays(2);

    $a = ($this->post)($this->f->body($this->f->cake, ['due_at' => $due->toIso8601String()]))->assertCreated();
    $b = ($this->post)($this->f->body($this->f->cake, ['due_at' => $due->addDay()->toIso8601String()]))->assertCreated();

    expect($a->json('id'))->not->toBe($b->json('id'))
        ->and(ProductionRequest::query()->where('reason', 'schedule')->count())->toBe(2);
});

it('refuses what production does not make: bought-in, made-to-order, an ingredient, no recipe, an unknown or another branch product', function () {
    $theirs = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $this->other->id]);
    $inactive = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $this->branch->id, 'is_active' => false, 'production_station_id' => $this->f->cakeRoomStation->id]);
    $this->f->recipe($inactive, '12', [[$this->f->flour, '1']]);

    foreach ([$this->f->soda->id, $this->f->sandwich->id, $this->f->flour->id, $this->f->plainCake->id, 999999, $theirs->id, $inactive->id] as $id) {
        ($this->post)(['client_uuid' => (string) Str::uuid(), 'product_item_id' => $id])
            ->assertStatus(422)
            ->assertJsonPath('message_key', 'production.product.not_producible');
    }
    expect(ProductionRequest::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a product nobody makes, with no producing station', function () {
    ($this->post)($this->f->body($this->f->orphanCake))
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'production.product.no_station');
});

it('validates the body: client_uuid, product, a positive decimal quantity that fits, a known priority and a date', function () {
    $bad = [
        [['client_uuid' => 'nope', 'product_item_id' => $this->f->cake->id]],
        [['product_item_id' => $this->f->cake->id]],
        [['client_uuid' => (string) Str::uuid()]],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'qty' => '0']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'qty' => '-3']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'qty' => 'lots']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'qty' => 12.5]],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'qty' => '1.00001']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'qty' => '99999999999']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'priority' => 'asap']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'due_at' => 'tomorrow-ish']],
        [['client_uuid' => (string) Str::uuid(), 'product_item_id' => $this->f->cake->id, 'note' => str_repeat('x', 501)]],
    ];
    foreach ($bad as [$body]) {
        ($this->post)($body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
    expect(ProductionRequest::query()->count())->toBe(0);
});

it('lets cashiers, chefs and the admin ask, and refuses a waiter and the bakers at the route', function () {
    foreach ([$this->f->waiter, $this->f->baker, $this->f->breadBaker] as $person) {
        loginAs($person);
        ($this->post)($this->f->body($this->f->cake))->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    loginAs($this->f->chef);
    ($this->post)($this->f->body($this->f->bread))->assertCreated();
    loginAs($this->f->admin);
    ($this->post)($this->f->body($this->f->cake))->assertCreated();
});

it('queues the notification listener of the request after commit, and nothing else but the stock evaluation', function () {
    Queue::fake();

    ($this->post)($this->f->body($this->f->cake))->assertCreated();

    assertNothingQueuedButStockEvaluation([NotifyProductionRequested::class]);
});

it('creates through the contract as the acting person: Requests::create returns the request, a stock-out one holds the open key', function () {
    Event::fake([ProductionRequested::class]);
    withActor($this->f->cashier->id);

    $manual = app(Requests::class)->create($this->f->cake->id, null, null, RequestReason::Manual);
    expect($manual)->toBeInstanceOf(ProductionRequest::class)
        ->and($manual->status)->toBe(RequestStatus::Requested)
        ->and((string) $manual->requested_qty)->toBe('12.0000')
        ->and($manual->station_id)->toBe($this->f->cakeRoomStation->id)
        ->and($manual->requested_by_user_id)->toBe($this->f->cashier->id)
        ->and($manual->open_stock_out_key)->toBeNull();

    $stockOut = app(Requests::class)->create($this->f->bread->id, '25', null, RequestReason::StockOut);
    $again = app(Requests::class)->create($this->f->bread->id, '25', null, RequestReason::StockOut);
    expect($stockOut->open_stock_out_key)->toBe(1)
        ->and($stockOut->reason)->toBe(RequestReason::StockOut)
        ->and((string) $stockOut->requested_qty)->toBe('25.0000')
        ->and($again->id)->toBe($stockOut->id)
        ->and(ProductionRequest::query()->where('product_item_id', $this->f->bread->id)->count())->toBe(1);
    Event::assertDispatchedTimes(ProductionRequested::class, 2);
    expect(AuditLog::query()->where('action', 'production_request.create')->count())->toBe(2);

    expect(fn () => app(Requests::class)->create($this->f->soda->id, null, null, RequestReason::Manual))
        ->toThrow(ProductionError::class);
});
