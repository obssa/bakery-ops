<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Events\StockChanged;
use App\Shared\Events\TransferDecided;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TransferFixture;

/*
 * POST /transfers/{transfer}/confirm and /reject (step 2.3; phase-2 plan sections 4, 5, 10.3 and 10.4). A confirm is one
 * AuditedAction: lock the transfer row, lock both balance rows through the ledger in its fixed order, refuse when the
 * source's `available` (on hand minus reserved) is below the quantity (409 inventory.transfer.insufficient, nothing
 * written, the row stays requested), write the two `transfer` movements in ONE Ledger::appendMany, set the status, and
 * announce TransferDecided after the commit. A reject writes no movement. Repeating a decision answers 200 with the
 * transfer and writes nothing; the opposite decision is a 409 that names the status. Stock never goes negative.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = TransferFixture::build($this->branch);
    loginAs($this->f->manager);
    // the baker asked for 5 kg of flour for the cake room: a requested row, as POST /transfers writes it
    $this->request = function (string $qty = '5000', ?object $item = null, ?object $to = null, ?object $from = null) {
        withActor($this->f->baker->id);

        return Transfer::factory()->create([
            'branch_id' => $this->branch->id,
            'item_id' => ($item ?? $this->f->flour)->id,
            'from_location_id' => ($from ?? $this->f->store)->id,
            'to_location_id' => ($to ?? $this->f->cakeRoom)->id,
            'qty' => $qty,
            'created_by_user_id' => $this->f->baker->id,
            'requested_by_user_id' => $this->f->baker->id,
        ]);
    };
    $this->confirm = fn (Transfer|int $t) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/transfers/'.($t instanceof Transfer ? $t->id : $t).'/confirm', []);
    $this->reject = fn (Transfer|int $t, array $body = ['reason' => 'No flour to spare']) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/transfers/'.($t instanceof Transfer ? $t->id : $t).'/reject', $body);
    $this->movements = fn () => DB::table('stock_movements')->where('reason', 'transfer')->orderBy('id')->get();
});

it('moves the stock on confirm: two transfer rows in one batch, out of the source and into the destination, with both ids', function () {
    stockIn($this->f->flour, $this->f->store, '20000');
    $transfer = ($this->request)('5000');
    Event::fake([TransferDecided::class]);

    $response = ($this->confirm)($transfer);

    $response->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('decided_by.id', $this->f->manager->id)
        ->assertJsonPath('requested_by.id', $this->f->baker->id)
        ->assertJsonPath('version', 2)
        ->assertJsonPath('reject_reason', null);
    expect($response->json('decided_at'))->not->toBeNull()->and($response->json())->not->toHaveKey('source_available');

    $rows = ($this->movements)();
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->item_id->toBe($this->f->flour->id)->qty->toBe('-5000.0000')->from_location_id->toBe($this->f->store->id)->to_location_id->toBe($this->f->cakeRoom->id)->ref_type->toBe('transfer')->ref_id->toBe($transfer->id)->user_id->toBe($this->f->manager->id)
        ->and($rows[1])->item_id->toBe($this->f->flour->id)->qty->toBe('5000.0000')->from_location_id->toBe($this->f->store->id)->to_location_id->toBe($this->f->cakeRoom->id)->ref_type->toBe('transfer')->ref_id->toBe($transfer->id)
        ->and($response->json('out_movement_id'))->toBe($rows[0]->id)
        ->and($response->json('in_movement_id'))->toBe($rows[1]->id)
        ->and(stockRow($this->f->flour, $this->f->store))->on_hand->toBe('15000.0000')
        ->and(stockRow($this->f->flour, $this->f->cakeRoom))->on_hand->toBe('5000.0000')
        ->and(AuditLog::query()->where('action', 'transfer.confirm')->where('subject_id', $transfer->id)->where('actor_id', $this->f->manager->id)->exists())->toBeTrue();
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);

    Event::assertDispatchedTimes(TransferDecided::class, 1);
    expect(Event::dispatched(TransferDecided::class)->first()[0]->payload())->toMatchArray([
        'transfer_id' => $transfer->id,
        'status' => 'confirmed',
        'reject_reason' => null,
        'item_id' => $this->f->flour->id,
        'item_name_en' => 'Flour',
        'item_name_am' => 'ዱቄት',
        'from_location_id' => $this->f->store->id,
        'from_location_name_en' => $this->f->store->name_en,
        'to_location_id' => $this->f->cakeRoom->id,
        'to_location_name_am' => $this->f->cakeRoom->name_am,
        'qty' => '5000.0000',
        'unit' => 'g',
        'requested_by_user_id' => $this->f->baker->id,
        'decided_by_user_id' => $this->f->manager->id,
    ]);
})->group('module:inventory', 'feature');

it('moves stock into a place the item has never been at, creating its balance row', function () {
    stockIn($this->f->flour, $this->f->store, '3000');
    expect(stockRow($this->f->flour, $this->f->kitchen))->toBeNull();
    $transfer = ($this->request)('3000', null, $this->f->kitchen);

    ($this->confirm)($transfer)->assertOk()->assertJsonPath('status', 'confirmed');

    expect(stockRow($this->f->flour, $this->f->kitchen))->on_hand->toBe('3000.0000')
        ->and(stockRow($this->f->flour, $this->f->store))->on_hand->toBe('0.0000');
})->group('module:inventory', 'feature');

it('announces the change of both balances once each, and queues only the stock evaluations', function () {
    stockIn($this->f->flour, $this->f->store, '9000');
    $transfer = ($this->request)('4000');
    Event::fake([StockChanged::class]);
    Queue::fake();

    ($this->confirm)($transfer)->assertOk();

    Event::assertDispatchedTimes(StockChanged::class, 2);
    assertNothingQueuedButStockEvaluation();
})->group('module:inventory', 'feature');

it('refuses a confirm for more than the source has available: 409, nothing written, the row stays requested', function () {
    stockIn($this->f->flour, $this->f->store, '4000');
    $transfer = ($this->request)('5000');
    $before = ledgerOracle($this->branch->id);
    $audits = AuditLog::query()->count();
    Event::fake([TransferDecided::class]);

    $response = ($this->confirm)($transfer);

    $response->assertStatus(409)
        ->assertJsonPath('message_key', 'inventory.transfer.insufficient')
        ->assertJsonPath('available', '4000.0000')
        ->assertJsonPath('requested', '5000.0000');
    expect($response->json('message_en'))->toBeString()->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u');
    $fresh = Transfer::query()->findOrFail($transfer->id);
    expect($fresh->status->value)->toBe('requested')
        ->and($fresh->version)->toBe(1)
        ->and($fresh->out_movement_id)->toBeNull()
        ->and(($this->movements)())->toHaveCount(0)
        ->and(ledgerOracle($this->branch->id))->toBe($before)
        ->and(AuditLog::query()->count())->toBe($audits, 'a refused confirm leaves no audit row either');
    Event::assertNotDispatched(TransferDecided::class);

    // the store keeper can still reject it, or restock and confirm
    stockIn($this->f->flour, $this->f->store, '1000');
    ($this->confirm)($transfer)->assertOk()->assertJsonPath('status', 'confirmed');
})->group('module:inventory', 'feature');

it('counts only what is available: stock held for orders cannot be moved away', function () {
    stockIn($this->f->flour, $this->f->store, '10000');
    app(Reservations::class)->reserve([new ReserveLine($this->f->flour->id, '6000', $this->f->store->id)], (string) Str::uuid());
    $five = ($this->request)('5000');
    $four = ($this->request)('4000');

    ($this->confirm)($five)->assertStatus(409)->assertJsonPath('available', '4000.0000');
    ($this->confirm)($four)->assertOk();

    expect(stockRow($this->f->flour, $this->f->store))->on_hand->toBe('6000.0000')->reserved->toBe('6000.0000');
})->group('module:inventory', 'feature');

it('moves everything that is there and leaves exactly zero, never below', function () {
    stockIn($this->f->flour, $this->f->store, '5000');
    $all = ($this->request)('5000');
    $one = ($this->request)('0.0001');

    ($this->confirm)($all)->assertOk();
    ($this->confirm)($one)->assertStatus(409)->assertJsonPath('available', '0.0000');

    expect(stockRow($this->f->flour, $this->f->store))->on_hand->toBe('0.0000');
    expect(DB::table('stock_balances')->where('on_hand', '<', 0)->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a confirm when the stock was spent between the request and the confirm, judging by what is there at the confirm', function () {
    stockIn($this->f->flour, $this->f->store, '5000');
    $transfer = ($this->request)('5000');
    stockIn($this->f->flour, $this->f->store, '-2000', MovementReason::Waste);

    ($this->confirm)($transfer)->assertStatus(409)->assertJsonPath('available', '3000.0000');
    expect(Transfer::query()->findOrFail($transfer->id)->status->value)->toBe('requested');
})->group('module:inventory', 'feature');

it('moves a finished product too: the cake room to the display case', function () {
    stockIn($this->f->cake, $this->f->cakeRoom, '6');
    $transfer = ($this->request)('2', $this->f->cake, $this->f->display, $this->f->cakeRoom);

    ($this->confirm)($transfer)->assertOk()->assertJsonPath('item.unit', 'pcs');

    expect(stockRow($this->f->cake, $this->f->display))->on_hand->toBe('2.0000')
        ->and(stockRow($this->f->cake, $this->f->cakeRoom))->on_hand->toBe('4.0000');
})->group('module:inventory', 'feature');

it('answers a second confirm with the confirmed transfer and writes nothing, not even an audit row', function () {
    stockIn($this->f->flour, $this->f->store, '9000');
    $transfer = ($this->request)('5000');
    $first = ($this->confirm)($transfer)->assertOk();
    $counts = fn () => [DB::table('stock_movements')->count(), AuditLog::query()->count(), (int) Transfer::query()->findOrFail($transfer->id)->version];
    $before = $counts();
    Event::fake([TransferDecided::class]);

    $second = ($this->confirm)($transfer)->assertOk();

    expect($counts())->toBe($before)
        ->and($second->json('status'))->toBe('confirmed')
        ->and($second->json('decided_at'))->toBe($first->json('decided_at'))
        ->and($second->json('out_movement_id'))->toBe($first->json('out_movement_id'));
    Event::assertNotDispatched(TransferDecided::class);
})->group('module:inventory', 'feature');

it('rejects with a reason and writes no movement; the request can be rejected again with the first reason kept', function () {
    stockIn($this->f->flour, $this->f->store, '9000');
    $transfer = ($this->request)('5000');
    $before = ledgerOracle($this->branch->id);
    Event::fake([TransferDecided::class]);

    $response = ($this->reject)($transfer, ['reason' => 'The sack is for the wedding']);

    $response->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('reject_reason', 'The sack is for the wedding')
        ->assertJsonPath('decided_by.id', $this->f->manager->id)
        ->assertJsonPath('out_movement_id', null)
        ->assertJsonPath('version', 2);
    expect(($this->movements)())->toHaveCount(0)
        ->and(ledgerOracle($this->branch->id))->toBe($before)
        ->and(AuditLog::query()->where('action', 'transfer.reject')->where('subject_id', $transfer->id)->where('actor_id', $this->f->manager->id)->exists())->toBeTrue();
    Event::assertDispatchedTimes(TransferDecided::class, 1);
    expect(Event::dispatched(TransferDecided::class)->first()[0]->payload())->toMatchArray([
        'transfer_id' => $transfer->id, 'status' => 'rejected', 'reject_reason' => 'The sack is for the wedding',
        'requested_by_user_id' => $this->f->baker->id, 'decided_by_user_id' => $this->f->manager->id,
    ]);

    $audits = AuditLog::query()->count();
    ($this->reject)($transfer, ['reason' => 'a second reason'])->assertOk()->assertJsonPath('reject_reason', 'The sack is for the wedding')->assertJsonPath('version', 2);
    expect(AuditLog::query()->count())->toBe($audits);
    Event::assertDispatchedTimes(TransferDecided::class, 1);
})->group('module:inventory', 'feature');

it('requires a reason to reject, of at most 255 characters', function () {
    $transfer = ($this->request)('1000');

    ($this->reject)($transfer, [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    ($this->reject)($transfer, ['reason' => '  '])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    ($this->reject)($transfer, ['reason' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    expect(Transfer::query()->findOrFail($transfer->id)->status->value)->toBe('requested');
})->group('module:inventory', 'feature');

it('names the status when the opposite decision is asked: 409, nothing changes', function () {
    stockIn($this->f->flour, $this->f->store, '9000');
    $confirmed = ($this->request)('1000');
    $rejected = ($this->request)('1000');
    ($this->confirm)($confirmed)->assertOk();
    ($this->reject)($rejected)->assertOk();
    $movements = DB::table('stock_movements')->count();

    ($this->reject)($confirmed)->assertStatus(409)->assertJsonPath('message_key', 'inventory.transfer.confirmed')->assertJsonPath('status', 'confirmed');
    ($this->confirm)($rejected)->assertStatus(409)->assertJsonPath('message_key', 'inventory.transfer.rejected')->assertJsonPath('status', 'rejected');

    expect(DB::table('stock_movements')->count())->toBe($movements)
        ->and(Transfer::query()->findOrFail($confirmed->id)->status->value)->toBe('confirmed')
        ->and(Transfer::query()->findOrFail($rejected->id)->status->value)->toBe('rejected');
})->group('module:inventory', 'feature');

it('decides only for the people who hold transfers.confirm, and who work at the source', function () {
    stockIn($this->f->flour, $this->f->store, '9000');
    $transfer = ($this->request)('1000');

    // the baker and the chef do not hold transfers.confirm at all
    foreach ([$this->f->baker, $this->f->chef, $this->f->cashier, $this->f->waiter] as $person) {
        loginAs($person);
        ($this->confirm)($transfer)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
        ($this->reject)($transfer)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    }

    // a chef granted the permission by hand still works at the kitchen, not at the store that holds the flour
    $this->f->chef->givePermissionTo('transfers.confirm');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    loginAs($this->f->chef->fresh());
    ($this->confirm)($transfer)->assertForbidden()->assertJsonPath('message_key', 'inventory.transfer.source_forbidden');
    ($this->reject)($transfer)->assertForbidden()->assertJsonPath('message_key', 'inventory.transfer.source_forbidden');
    expect(Transfer::query()->findOrFail($transfer->id)->status->value)->toBe('requested');

    // assigned to the store as well, they decide it
    UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->f->chef->id, 'station_id' => $this->f->storeStation->id]);
    ($this->confirm)($transfer)->assertOk()->assertJsonPath('decided_by.id', $this->f->chef->id);
})->group('module:inventory', 'feature');

it('treats another branch transfer as not found', function () {
    $strangers = TransferFixture::build(Branch::factory()->create());
    actInBranch($this->branch);
    withActor($this->f->admin->id);
    $foreign = Transfer::factory()->create([
        'branch_id' => $strangers->branch->id, 'item_id' => $strangers->flour->id, 'from_location_id' => $strangers->store->id,
        'to_location_id' => $strangers->kitchen->id, 'created_by_user_id' => $strangers->admin->id, 'requested_by_user_id' => $strangers->admin->id,
    ]);

    ($this->confirm)($foreign->id)->assertNotFound();
    ($this->reject)($foreign->id)->assertNotFound();
    ($this->confirm)(999999)->assertNotFound();
})->group('module:inventory', 'feature');
