<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Notifications\Listeners\NotifyTransferRequested;
use App\Shared\Events\TransferRequested;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\TransferFixture;

/*
 * GET /transfers and POST /transfers (step 2.3; phase-2 plan sections 4, 5 and 10.3). A request asks the store for stock
 * on behalf of a station: it writes a `requested` row and NO movement, and the destination must be the requester's own
 * station's location (stations.all holders may ask for any). The quantity is typed in a unit and stored in base units; the
 * source defaults to the store. The client UUID makes an offline replay answer the same transfer (200, first answer 201).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->f = TransferFixture::build($this->branch);
    loginAs($this->f->baker);
    $this->post = fn (array $body) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/transfers', $body);
    // the baker asks the store for 5 kg of flour for the cake room, typed in kilograms
    $this->flourForCakeRoom = fn (array $over = []): array => $this->f->body($this->f->flour, $this->f->cakeRoom, '5', $over + ['unit_id' => catalogUnit('kg')->id]);
});

it('writes a requested transfer and no movement, from the store by default, in base units', function () {
    stockIn($this->f->flour, $this->f->store, '20000');
    $movements = DB::table('stock_movements')->count();
    $before = ledgerOracle($this->branch->id);
    Event::fake([TransferRequested::class]);

    $response = ($this->post)(($this->flourForCakeRoom)(['note' => 'for the wedding cake']));

    $response->assertCreated()
        ->assertJsonPath('status', 'requested')
        ->assertJsonPath('item.id', $this->f->flour->id)
        ->assertJsonPath('item.name_en', 'Flour')
        ->assertJsonPath('item.name_am', 'ዱቄት')
        ->assertJsonPath('item.unit', 'g')
        ->assertJsonPath('from_location.id', $this->f->store->id)
        ->assertJsonPath('to_location.id', $this->f->cakeRoom->id)
        ->assertJsonPath('qty', '5000.0000')
        ->assertJsonPath('note', 'for the wedding cake')
        ->assertJsonPath('reject_reason', null)
        ->assertJsonPath('requested_by.id', $this->f->baker->id)
        ->assertJsonPath('decided_by', null)
        ->assertJsonPath('decided_at', null)
        ->assertJsonPath('production_request_id', null)
        ->assertJsonPath('source_available', '20000.0000')
        ->assertJsonPath('out_movement_id', null)
        ->assertJsonPath('in_movement_id', null)
        ->assertJsonPath('version', 1);
    expect($response->json('requested_at'))->not->toBeNull();

    $transfer = Transfer::query()->findOrFail($response->json('id'));
    expect($transfer->status->value)->toBe('requested')
        ->and($transfer->created_by_user_id)->toBe($this->f->baker->id)
        ->and($transfer->requested_by_user_id)->toBe($this->f->baker->id)
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'a request moves nothing')
        ->and(ledgerOracle($this->branch->id))->toBe($before)
        ->and(AuditLog::query()->where('action', 'transfer.request')->where('subject_id', $transfer->id)->where('actor_id', $this->f->baker->id)->exists())->toBeTrue();

    Event::assertDispatchedTimes(TransferRequested::class, 1);
    $event = Event::dispatched(TransferRequested::class)->first()[0];
    expect($event->branchId)->toBe($this->branch->id)
        ->and($event->payload())->toMatchArray([
            'transfer_id' => $transfer->id,
            'item_id' => $this->f->flour->id,
            'item_name_en' => 'Flour',
            'item_name_am' => 'ዱቄት',
            'from_location_id' => $this->f->store->id,
            'from_location_name_en' => $this->f->store->name_en,
            'from_location_name_am' => $this->f->store->name_am,
            'from_station_id' => $this->f->storeStation->id,
            'to_location_id' => $this->f->cakeRoom->id,
            'to_location_name_en' => $this->f->cakeRoom->name_en,
            'to_location_name_am' => $this->f->cakeRoom->name_am,
            'to_station_id' => $this->f->cakeRoomStation->id,
            'qty' => '5000.0000',
            'unit' => 'g',
            'requested_by_user_id' => $this->f->baker->id,
            'production_request_id' => null,
        ]);
})->group('module:inventory', 'feature');

it('takes the quantity in base units when no unit is named, and converts a named unit', function () {
    $grams = ($this->post)($this->f->body($this->f->flour, $this->f->cakeRoom, '1250.5'))->assertCreated();
    $kilos = ($this->post)($this->f->body($this->f->flour, $this->f->cakeRoom, '2.5', ['unit_id' => catalogUnit('kg')->id]))->assertCreated();

    expect($grams->json('qty'))->toBe('1250.5000')->and($kilos->json('qty'))->toBe('2500.0000');
})->group('module:inventory', 'feature');

it('refuses a unit that does not measure the item', function () {
    ($this->post)($this->f->body($this->f->flour, $this->f->cakeRoom, '2', ['unit_id' => catalogUnit('pcs')->id]))
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'catalog.unit.incompatible');
    expect(Transfer::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('takes the source named, a note and the production request it came from', function () {
    $response = ($this->post)(($this->flourForCakeRoom)(['from_location_id' => $this->f->kitchen->id, 'production_request_id' => 41]))->assertCreated();

    $response->assertJsonPath('from_location.id', $this->f->kitchen->id)->assertJsonPath('production_request_id', 41);
})->group('module:inventory', 'feature');

it('answers a replay of the same client_uuid with the same transfer, 200, writing and announcing nothing', function () {
    Event::fake([TransferRequested::class]);
    $body = ($this->flourForCakeRoom)();
    $first = ($this->post)($body)->assertCreated();
    $audits = AuditLog::query()->count();

    // the Idempotency-Key was lost, the client UUID was not
    $second = ($this->post)($body)->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(Transfer::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);
    Event::assertDispatchedTimes(TransferRequested::class, 1);
})->group('module:inventory', 'feature');

it('allows a request for more than the store has: the check is made at the confirm, under the lock', function () {
    stockIn($this->f->flour, $this->f->store, '1000');

    ($this->post)(($this->flourForCakeRoom)())->assertCreated()->assertJsonPath('source_available', '1000.0000');
})->group('module:inventory', 'feature');

it('lets a person ask for their own station only, and a stations.all holder for any', function () {
    // the chef works at the kitchen: the cake room is not theirs
    loginAs($this->f->chef);
    ($this->post)(($this->flourForCakeRoom)())->assertForbidden()->assertJsonPath('message_key', 'inventory.transfer.destination_forbidden');
    ($this->post)($this->f->body($this->f->flour, $this->f->kitchen, '1000'))->assertCreated();

    // the cashier works at the till, whose location is the display case
    loginAs($this->f->cashier);
    ($this->post)($this->f->body($this->f->cake, $this->f->display, '6', ['from_location_id' => $this->f->cakeRoom->id]))->assertCreated();
    ($this->post)($this->f->body($this->f->cake, $this->f->kitchen, '6', ['from_location_id' => $this->f->cakeRoom->id]))->assertForbidden();

    // the manager and the admin work at every station
    foreach ([$this->f->manager, $this->f->admin] as $person) {
        loginAs($person);
        ($this->post)(($this->flourForCakeRoom)())->assertCreated();
    }
    expect(Transfer::query()->count())->toBe(4);
})->group('module:inventory', 'feature');

it('refuses a destination with no station to anyone who does not work at every station', function () {
    $nowhere = stockLocation($this->branch, LocationType::Bar);

    ($this->post)($this->f->body($this->f->flour, $nowhere, '1000'))->assertForbidden()->assertJsonPath('message_key', 'inventory.transfer.destination_forbidden');
    loginAs($this->f->manager);
    ($this->post)($this->f->body($this->f->flour, $nowhere, '1000'))->assertCreated();
})->group('module:inventory', 'feature');

it('refuses the waiter, who holds transfers.request nowhere', function () {
    loginAs($this->f->waiter);

    ($this->post)(($this->flourForCakeRoom)())->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    $this->getJson('/api/v1/transfers')->assertForbidden();
})->group('module:inventory', 'feature');

it('refuses a transfer between the same place, of an item that is not stocked, and to or from a place that is not there', function () {
    ($this->post)(($this->flourForCakeRoom)(['from_location_id' => $this->f->cakeRoom->id]))
        ->assertStatus(422)->assertJsonPath('message_key', 'inventory.transfer.same_location');
    // a made-to-order sandwich is never in stock, and another branch's flour does not exist here
    ($this->post)($this->f->body($this->f->sandwich, $this->f->cakeRoom, '1'))
        ->assertStatus(422)->assertJsonPath('message_key', 'inventory.transfer.item_not_stockable');
    $strangers = TransferFixture::build($this->other);
    withActor($this->f->admin->id);
    ($this->post)($this->f->body($strangers->flour, $this->f->cakeRoom, '1'))
        ->assertStatus(422)->assertJsonPath('message_key', 'inventory.transfer.item_not_stockable');
    ($this->post)($this->f->body($this->f->flour, $strangers->kitchen, '1'))
        ->assertStatus(422)->assertJsonPath('message_key', 'inventory.transfer.location_unknown');
    ($this->post)(($this->flourForCakeRoom)(['from_location_id' => $strangers->store->id]))
        ->assertStatus(422)->assertJsonPath('message_key', 'inventory.transfer.location_unknown');
    expect(Transfer::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a request when the branch has no store and no source is named', function () {
    DB::table('locations')->where('id', $this->f->store->id)->update(['deleted_at' => now()]);

    ($this->post)(($this->flourForCakeRoom)())->assertStatus(422)->assertJsonPath('message_key', 'inventory.transfer.location_unknown');
})->group('module:inventory', 'feature');

it('validates the body: a uuid, ids, a positive decimal with four decimals at most, a short note', function () {
    $post = fn (array $over) => ($this->post)(($this->flourForCakeRoom)($over));

    $post(['client_uuid' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['client_uuid']);
    $post(['qty' => '0'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => '-3'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => 'lots'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => '1.23456'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    $post(['qty' => '99999999'])->assertStatus(422)->assertJsonValidationErrors(['qty']); // 99 999 999 kg is more grams than the column holds
    $post(['item_id' => 'flour'])->assertStatus(422)->assertJsonValidationErrors(['item_id']);
    $post(['to_location_id' => null])->assertStatus(422)->assertJsonValidationErrors(['to_location_id']);
    $post(['note' => Str::repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors(['note']);
    $post(['unit_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
    $post(['qty' => '99999999999'])->assertStatus(422)->assertJsonValidationErrors(['qty']);
    expect(Transfer::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('queues nothing but a stock evaluation', function () {
    Queue::fake();

    ($this->post)(($this->flourForCakeRoom)())->assertCreated();

    assertNothingQueuedButStockEvaluation([NotifyTransferRequested::class]);
})->group('module:inventory', 'feature');

it('lists the transfers of the branch newest first, and filters by status, either end, the requester and the production request', function () {
    $mine = ($this->post)(($this->flourForCakeRoom)(['production_request_id' => 7]))->assertCreated()->json('id');
    loginAs($this->f->chef);
    $kitchen = ($this->post)($this->f->body($this->f->sugar, $this->f->kitchen, '500'))->assertCreated()->json('id');
    loginAs($this->f->manager);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/transfers/{$kitchen}/reject", ['reason' => 'No sugar'])->assertOk();
    // another branch's transfers never appear
    $strangers = TransferFixture::build($this->other);
    Transfer::factory()->create(['branch_id' => $this->other->id, 'item_id' => $strangers->flour->id, 'from_location_id' => $strangers->store->id, 'to_location_id' => $strangers->kitchen->id, 'created_by_user_id' => $strangers->admin->id, 'requested_by_user_id' => $strangers->admin->id]);
    actInBranch($this->branch);
    $ids = fn (string $query = '') => collect($this->getJson('/api/v1/transfers'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids())->toBe([$kitchen, $mine])
        ->and($ids('?status=requested'))->toBe([$mine])
        ->and($ids('?status=rejected'))->toBe([$kitchen])
        ->and($ids('?location_id='.$this->f->store->id))->toBe([$kitchen, $mine], 'the store is the source of both')
        ->and($ids('?location_id='.$this->f->cakeRoom->id))->toBe([$mine], 'the destination counts too')
        ->and($ids('?location_id='.$this->f->kitchen->id))->toBe([$kitchen])
        ->and($ids('?production_request_id=7'))->toBe([$mine])
        ->and($ids('?per_page=1'))->toBe([$kitchen]);

    loginAs($this->f->baker);
    expect($ids('?mine=1'))->toBe([$mine]);
    loginAs($this->f->chef);
    expect($ids('?mine=1'))->toBe([$kitchen]);
    $this->getJson('/api/v1/transfers?status=done')->assertStatus(422);
})->group('module:inventory', 'feature');

it('shows the live source_available only while a transfer is requested', function () {
    stockIn($this->f->flour, $this->f->store, '9000');
    $id = ($this->post)(($this->flourForCakeRoom)())->assertCreated()->json('id');
    $list = fn () => collect($this->getJson('/api/v1/transfers')->assertOk()->json('data'))->firstWhere('id', $id);

    expect($list()['source_available'])->toBe('9000.0000');
    stockIn($this->f->flour, $this->f->store, '-4000', MovementReason::Waste);
    expect($list()['source_available'])->toBe('5000.0000');

    loginAs($this->f->manager);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/transfers/{$id}/confirm", [])->assertOk();
    expect($list())->not->toHaveKey('source_available');
})->group('module:inventory', 'feature');
