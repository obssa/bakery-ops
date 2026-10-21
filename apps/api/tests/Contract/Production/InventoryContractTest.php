<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\Transfers;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Exceptions\TransferError;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Events\StockChanged;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Contract: production -> inventory (manifest contracts["production->inventory"]; phase-2 plan section 2; ADR 0011).
 *
 * Written BEFORE any code exists in App\Modules\Production (step 2.1): only Inventory classes, the Catalog classes
 * Inventory stands on, the Shared events and factories. Phrased from Production's side: "when I record a batch I rely on
 * one all-or-nothing write of the consumption and the output; when a product runs out I rely on being told once, and on
 * the work that is queued behind that telling carrying the person whose sale caused it; when I put the finished goods
 * where the pad reads them the pad sees them at once".
 * It runs in Inventory's CI job (mod-inventory) for the life of the project. The transfer cases (Transfers::request /
 * confirm / reject: "I ask the store for flour and it moves only when someone confirms there was enough") were added at
 * step 2.3, before the code they prove, as the plan says.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->baker = makeUser($this->branch, 'cake_baker');
    $this->padAccount = makeUser($this->branch, 'waiter', ['name' => 'Pad 1']);
    $this->waiter = makeUser($this->branch, 'waiter');
    withActor($this->admin->id);

    $this->cakeRoomStation = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->cakeRoom = stockLocation($this->branch, LocationType::CakeRoom, $this->cakeRoomStation);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase, $this->tillStation);

    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Cake flour']);
    $this->sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Sugar']);
    $this->cocoa = Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => 'Cocoa']);
    // a cake: counter product made in the cake room, sold from the display case
    $this->cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create([
        'branch_id' => $b, 'name_en' => 'Black forest cake', 'name_am' => 'ብላክ ፎረስት', 'production_station_id' => $this->cakeRoomStation->id,
    ]);
    // toast bread: a to_stock product that is routed to the kitchen, so the pad reads the kitchen, not the display case
    $this->toast = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $b, 'name_en' => 'Toast bread']);
    ItemStationAssignment::factory()->create(['branch_id' => $b, 'item_id' => $this->toast->id, 'station_id' => $this->kitchenStation->id]);

    $this->reservations = app(Reservations::class);
    $this->ledger = app(Ledger::class);
    $this->movementsOf = fn (Item $item, ?Location $at = null) => DB::table('stock_movements')
        ->where('item_id', $item->id)
        ->when($at !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_location_id', $at->id)->orWhere('to_location_id', $at->id)))
        ->orderBy('id')->get();
    $this->alertRows = fn (Item $item, Location $at) => DB::table('stock_alert_states')->where('item_id', $item->id)->where('location_id', $at->id)->orderBy('id')->get();
    $this->reserve = fn (Item $item, string $qty, Location $at): string => tap((string) Str::uuid(), fn (string $ref) => $this->reservations->reserve([new ReserveLine($item->id, $qty, $at->id)], $ref));
});

it('writes N production_consume rows and one production_output row all or nothing, with one actor stamp', function () {
    stockIn($this->flour, $this->cakeRoom, '20000'); // 20 kg in grams
    stockIn($this->sugar, $this->cakeRoom, '5000');
    stockIn($this->cocoa, $this->cakeRoom, '300');
    $batch = fn (string $cocoa) => [
        MovementIntent::of($this->flour->id, $this->cakeRoom->id, '-3000', MovementReason::ProductionConsume, 'production_batch', 1),
        MovementIntent::of($this->sugar->id, $this->cakeRoom->id, '-800', MovementReason::ProductionConsume, 'production_batch', 1),
        MovementIntent::of($this->cocoa->id, $this->cakeRoom->id, $cocoa, MovementReason::ProductionConsume, 'production_batch', 1),
        MovementIntent::of($this->cake->id, $this->display->id, '6', MovementReason::ProductionOutput, 'production_batch', 1),
    ];
    $before = ledgerOracle($this->branch->id);
    $movementCount = DB::table('stock_movements')->count();

    // the last ingredient is short (300 g held, 400 g wanted): the whole batch is refused, balances and rows unchanged
    try {
        $this->ledger->appendMany($batch('-400'));
        $this->fail('a batch whose last ingredient is short must be refused whole');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.stock.insufficient');
    }
    expect(DB::table('stock_movements')->count())->toBe($movementCount)
        ->and(ledgerOracle($this->branch->id))->toBe($before)
        ->and(($this->movementsOf)($this->cake))->toHaveCount(0);

    // the baker pressed Done on the cake room's tablet: admin account, acting as the baker, at the cake room
    withActor($this->admin->id, $this->baker->id, $this->cakeRoomStation->id);
    $rows = $this->ledger->appendMany($batch('-250'));

    expect($rows)->toHaveCount(4)
        ->and(DB::table('stock_movements')->count())->toBe($movementCount + 4);
    $written = DB::table('stock_movements')->where('ref_type', 'production_batch')->orderBy('id')->get();
    expect($written->pluck('reason')->all())->toBe(['production_consume', 'production_consume', 'production_consume', 'production_output'])
        ->and($written->map(fn ($r) => [$r->user_id, $r->acting_user_id, $r->station_id, $r->shift_instance_id, $r->device_id])->unique()->values()->all())
        ->toBe([[$this->admin->id, $this->baker->id, $this->cakeRoomStation->id, null, null]], 'one actor stamp on every row of the batch')
        ->and(stockRow($this->flour, $this->cakeRoom))->on_hand->toBe('17000.0000')
        ->and(stockRow($this->cocoa, $this->cakeRoom))->on_hand->toBe('50.0000')
        ->and(stockRow($this->cake, $this->display))->on_hand->toBe('6.0000');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['balances'])->toBe($oracle['movements'])->toBe($oracle['view']);
})->group('contract:production->inventory', 'contract');

it('shows a purchase_in in Stock::of at once, on_hand and available', function () {
    expect(app(Stock::class)->of($this->flour, $this->cakeRoom)->onHand->toString())->toBe('0.0000');

    stockIn($this->flour, $this->cakeRoom, '12500');

    $level = app(Stock::class)->of($this->flour, $this->cakeRoom);
    expect($level->onHand->toString())->toBe('12500.0000')
        ->and($level->reserved->toString())->toBe('0.0000')
        ->and($level->available()->toString())->toBe('12500.0000')
        ->and(app(Stock::class)->at($this->cakeRoom)[$this->flour->id]->available()->toString())->toBe('12500.0000');
})->group('contract:production->inventory', 'contract');

it('fires StockOut exactly once when available falls to 0, not for the movements that follow at 0 nor a second evaluation, and again after a recovery', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    stockIn($this->cake, $this->display, '5');
    Event::assertNotDispatched(StockOut::class);

    // the pad sends an order for the last 5: the reservation takes the last unit
    $ref = ($this->reserve)($this->cake, '5', $this->display);

    Event::assertDispatchedTimes(StockOut::class, 1);
    Event::assertNotDispatched(StockRecovered::class);
    $episodes = ($this->alertRows)($this->cake, $this->display);
    expect($episodes)->toHaveCount(1)
        ->and($episodes[0]->type)->toBe('out')
        ->and($episodes[0]->is_active)->toBe(1)
        ->and($episodes[0]->cleared_at)->toBeNull()
        ->and($episodes[0]->available_at_start)->toBe('0.0000');

    // the payload: item and place ids and names in both languages, kind, fulfilment, the numbers and the episode's id
    $first = Event::dispatched(StockOut::class)->first()[0];
    expect($first->payload())->toMatchArray([
        'item_id' => $this->cake->id,
        'location_id' => $this->display->id,
        'item_name_en' => 'Black forest cake',
        'item_name_am' => 'ብላክ ፎረስት',
        'location_name_en' => $this->display->name_en,
        'location_name_am' => $this->display->name_am,
        'item_kind' => 'product',
        'fulfilment' => 'to_stock',
        'available' => '0.0000',
        'on_hand' => '5.0000',
        'reserved' => '5.0000',
        'alert_id' => $episodes[0]->id,
    ])->and($first->branchId)->toBe($this->branch->id)
        ->and($first->toArray()['branch_id'])->toBe($this->branch->id);

    // the sale settles the reservation: on_hand and reserved both fall to 0, available stays 0, nothing fires
    $this->reservations->consume($ref, null, MovementReason::Sale);
    expect(stockRow($this->cake, $this->display))->on_hand->toBe('0.0000')->reserved->toBe('0.0000');
    Event::assertDispatchedTimes(StockOut::class, 1);

    // the same balance evaluated again (a redelivered StockChanged) opens nothing
    event(new StockChanged($this->branch->id, $this->cake->id, $this->display->id, '0.0000', '0.0000', '0.0000', []));
    event(new StockChanged($this->branch->id, $this->cake->id, $this->display->id, '0.0000', '0.0000', '0.0000', []));
    Event::assertDispatchedTimes(StockOut::class, 1);
    expect(($this->alertRows)($this->cake, $this->display))->toHaveCount(1);

    // 4 are baked: available rises above 0, the episode closes
    stockIn($this->cake, $this->display, '4', MovementReason::ProductionOutput);
    Event::assertDispatchedTimes(StockRecovered::class, 1);
    Event::assertDispatchedTimes(StockOut::class, 1);

    // and the next order takes them all: a new episode, a second StockOut
    ($this->reserve)($this->cake, '4', $this->display);
    Event::assertDispatchedTimes(StockOut::class, 2);
    $episodes = ($this->alertRows)($this->cake, $this->display);
    expect($episodes)->toHaveCount(2)
        ->and($episodes[0]->is_active)->toBeNull()
        ->and($episodes[0]->cleared_at)->not->toBeNull()
        ->and($episodes[1]->is_active)->toBe(1);
})->group('contract:production->inventory', 'contract');

it('fires StockRecovered once when available rises above 0 after a StockOut, which re-arms the alert', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    stockIn($this->cake, $this->display, '2');
    ($this->reserve)($this->cake, '2', $this->display);
    Event::assertDispatchedTimes(StockOut::class, 1);
    $alertId = ($this->alertRows)($this->cake, $this->display)[0]->id;

    stockIn($this->cake, $this->display, '3', MovementReason::ProductionOutput);

    Event::assertDispatchedTimes(StockRecovered::class, 1);
    $recovered = Event::dispatched(StockRecovered::class)->first()[0];
    expect($recovered->payload())->toMatchArray([
        'item_id' => $this->cake->id,
        'location_id' => $this->display->id,
        'type' => 'out',
        'alert_id' => $alertId,
    ]);

    // further stock in does not announce a recovery again
    stockIn($this->cake, $this->display, '1', MovementReason::ProductionOutput);
    Event::assertDispatchedTimes(StockRecovered::class, 1);

    // an item that never ran out never recovers
    stockIn($this->toast, $this->kitchen, '10');
    stockIn($this->toast, $this->kitchen, '10');
    Event::assertDispatchedTimes(StockRecovered::class, 1);
})->group('contract:production->inventory', 'contract');

it('reads availability of a to_stock product where Locations::forProduct says: the display case, or the kitchen when the product is routed there', function () {
    $availability = app(Availability::class);
    $locations = app(Locations::class);
    stockIn($this->cake, $this->display, '10');
    stockIn($this->toast, $this->kitchen, '24');
    ($this->reserve)($this->cake, '3', $this->display);
    ($this->reserve)($this->toast, '5', $this->kitchen);

    expect($locations->forProduct($this->cake)?->id)->toBe($this->display->id)
        ->and($locations->forProduct($this->toast)?->id)->toBe($this->kitchen->id)
        ->and(app(Routing::class)->stationFor($this->cake))->toBeNull()
        ->and($availability->ofProduct($this->cake, $locations->forProduct($this->cake))->toString())->toBe('7.0000', 'on_hand 10 - reserved 3 at the display case')
        ->and($availability->ofProduct($this->toast, $locations->forProduct($this->toast))->toString())->toBe('19.0000', 'on_hand 24 - reserved 5 at the kitchen')
        ->and($availability->ofProduct($this->toast, $this->display)->toString())->toBe('0.0000', 'nothing of the toast is at the display case')
        ->and($availability->bulk([$this->cake, $this->toast], $this->display)[$this->cake->id]->toString())->toBe('7.0000');
})->group('contract:production->inventory', 'contract');

it('shows a production_output written at Locations::forProduct to Availability in the same request', function () {
    $availability = app(Availability::class);
    $locations = app(Locations::class);
    stockIn($this->flour, $this->cakeRoom, '5000');
    expect($availability->ofProduct($this->cake, $locations->forProduct($this->cake))->toString())->toBe('0.0000');

    $this->ledger->appendMany([
        MovementIntent::of($this->flour->id, $this->cakeRoom->id, '-1000', MovementReason::ProductionConsume, 'production_batch', 7),
        MovementIntent::of($this->cake->id, $locations->forProduct($this->cake)->id, '6', MovementReason::ProductionOutput, 'production_batch', 7),
    ]);

    expect($availability->ofProduct($this->cake, $locations->forProduct($this->cake))->toString())->toBe('6.0000')
        ->and($availability->ofProduct($this->cake, $this->display)->toString())->toBe('6.0000');
})->group('contract:production->inventory', 'contract');

it('restores the actor of the movement that caused a stock-out in queued work: the StockOut and everything it triggers carries that person', function () {
    Queue::fake();
    stockIn($this->cake, $this->display, '3');
    // the waiter sells on the shared pad: the pad account is logged in, the waiter acts by short code, at the till
    withActor($this->padAccount->id, $this->waiter->id, $this->tillStation->id);
    ($this->reserve)($this->cake, '3', $this->display);

    // the movement only queued the evaluation: no episode yet, nothing announced
    Queue::assertPushed(EvaluateBalance::class);
    expect(($this->alertRows)($this->cake, $this->display))->toHaveCount(0);
    $jobs = Queue::pushed(EvaluateBalance::class)->filter(fn (EvaluateBalance $job): bool => $job->itemId === $this->cake->id && $job->locationId === $this->display->id);
    expect($jobs)->not->toBeEmpty();

    // the worker: no request, no actor, no branch, a fresh process
    app(ActorContext::class)->reset();
    app(CurrentBranch::class)->clear();
    expect(app(ActorContext::class)->isAuthenticated())->toBeFalse();
    $seen = [];
    Event::listen(StockOut::class, function (StockOut $event) use (&$seen): void {
        $seen[] = ['event' => $event->payload()['caused_by_user_id'], 'actor' => app(ActorContext::class)->toArray(), 'branch' => app(CurrentBranch::class)->idOrNull()];
    });

    app()->call([$jobs->first(), 'handle']);

    $episode = ($this->alertRows)($this->cake, $this->display)->first();
    expect($episode)->not->toBeNull()
        ->and($episode->caused_by_user_id)->toBe($this->waiter->id)
        ->and($seen)->toHaveCount(1)
        ->and($seen[0]['event'])->toBe($this->waiter->id, 'the person who physically sold it')
        ->and($seen[0]['actor'])->toBe([
            'user_id' => $this->padAccount->id,
            'acting_user_id' => $this->waiter->id,
            'shift_instance_id' => null,
            'station_id' => $this->tillStation->id,
            'device_id' => null,
        ])
        ->and($seen[0]['branch'])->toBe($this->branch->id);

    // and the worker is left as it was found: no leaked actor, no leaked branch
    expect(app(ActorContext::class)->isAuthenticated())->toBeFalse()
        ->and(app(CurrentBranch::class)->isResolved())->toBeFalse();
})->group('contract:production->inventory', 'contract');

it('writes a requested transfer and no movement when a transfer is requested, from the store by default', function () {
    stockIn($this->flour, $this->store, '20000');
    $movements = DB::table('stock_movements')->count();
    $before = ledgerOracle($this->branch->id);

    // the cake baker asks for 5 kg of flour for the cake room, where they work; no source named: the store
    UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->baker->id, 'station_id' => $this->cakeRoomStation->id]);
    withActor($this->admin->id, $this->baker->id, $this->cakeRoomStation->id);
    $transfer = app(Transfers::class)->request($this->flour, $this->cakeRoom, '5000', (string) Str::uuid(), null, 'for the wedding cake');

    expect($transfer)->toBeInstanceOf(Transfer::class)
        ->and($transfer->status->value)->toBe('requested')
        ->and($transfer->from_location_id)->toBe($this->store->id)
        ->and($transfer->to_location_id)->toBe($this->cakeRoom->id)
        ->and($transfer->qty->toString())->toBe('5000.0000')
        ->and($transfer->note)->toBe('for the wedding cake')
        ->and($transfer->requested_by_user_id)->toBe($this->baker->id, 'the person who asked: the one acting, not the shared account')
        ->and($transfer->out_movement_id)->toBeNull()
        ->and($transfer->in_movement_id)->toBeNull()
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'a request moves nothing')
        ->and(ledgerOracle($this->branch->id))->toBe($before)
        ->and(app(Stock::class)->of($this->flour, $this->store)->available()->toString())->toBe('20000.0000')
        ->and(app(Stock::class)->of($this->flour, $this->cakeRoom)->onHand->toString())->toBe('0.0000');
})->group('contract:production->inventory', 'contract');

it('writes the two transfer rows, out of the source and into the destination, in one appendMany when a transfer is confirmed', function () {
    stockIn($this->flour, $this->store, '20000');
    $transfer = app(Transfers::class)->request($this->flour, $this->cakeRoom, '5000', (string) Str::uuid());
    $count = DB::table('stock_movements')->count();

    // the store keeper confirms: one actor stamp on both rows
    $confirmed = app(Transfers::class)->confirm($transfer);

    expect($confirmed->status->value)->toBe('confirmed')
        ->and($confirmed->decided_by_user_id)->toBe($this->admin->id)
        ->and(DB::table('stock_movements')->count())->toBe($count + 2);
    $rows = DB::table('stock_movements')->where('ref_type', 'transfer')->where('ref_id', $transfer->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('reason')->unique()->all())->toBe(['transfer'])
        ->and($rows[0])->qty->toBe('-5000.0000')->from_location_id->toBe($this->store->id)->to_location_id->toBe($this->cakeRoom->id)
        ->and($rows[1])->qty->toBe('5000.0000')->from_location_id->toBe($this->store->id)->to_location_id->toBe($this->cakeRoom->id)
        ->and($rows->map(fn ($r) => [$r->user_id, $r->acting_user_id, $r->station_id])->unique()->values()->all())->toBe([[$this->admin->id, null, null]])
        ->and($confirmed->out_movement_id)->toBe($rows[0]->id)
        ->and($confirmed->in_movement_id)->toBe($rows[1]->id)
        ->and(stockRow($this->flour, $this->store))->on_hand->toBe('15000.0000')
        ->and(stockRow($this->flour, $this->cakeRoom))->on_hand->toBe('5000.0000');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['balances'])->toBe($oracle['movements'])->toBe($oracle['view']);
})->group('contract:production->inventory', 'contract');

it('refuses to confirm more than the source has available, under the lock, and the row stays requested', function () {
    stockIn($this->flour, $this->store, '10000');
    ($this->reserve)($this->flour, '6000', $this->store); // 10 kg on hand, 6 kg held for orders: 4 kg available
    $transfer = app(Transfers::class)->request($this->flour, $this->kitchen, '5000', (string) Str::uuid());
    $count = DB::table('stock_movements')->count();
    $before = ledgerOracle($this->branch->id);

    try {
        app(Transfers::class)->confirm($transfer);
        $this->fail('5 kg were asked for and 4 kg are available');
    } catch (TransferError $e) {
        expect($e->messageKey)->toBe('inventory.transfer.insufficient')
            ->and($e->status)->toBe(409)
            ->and($e->extra)->toMatchArray(['available' => '4000.0000', 'requested' => '5000.0000']);
    }

    expect(Transfer::query()->findOrFail($transfer->id)->status->value)->toBe('requested')
        ->and(DB::table('stock_movements')->count())->toBe($count)
        ->and(ledgerOracle($this->branch->id))->toBe($before);

    // what is available can be moved: 4 kg is exactly enough
    $four = app(Transfers::class)->request($this->flour, $this->kitchen, '4000', (string) Str::uuid());
    app(Transfers::class)->confirm($four);
    expect(stockRow($this->flour, $this->store))->on_hand->toBe('6000.0000')->reserved->toBe('6000.0000')
        ->and(app(Stock::class)->of($this->flour, $this->store)->available()->toString())->toBe('0.0000');
})->group('contract:production->inventory', 'contract');

it('writes no movement when a transfer is rejected, and keeps the reason', function () {
    stockIn($this->flour, $this->store, '3000');
    $transfer = app(Transfers::class)->request($this->flour, $this->kitchen, '1000', (string) Str::uuid());
    $count = DB::table('stock_movements')->count();
    $before = ledgerOracle($this->branch->id);

    $rejected = app(Transfers::class)->reject($transfer, 'The sack is reserved for the wedding');

    expect($rejected->status->value)->toBe('rejected')
        ->and($rejected->reject_reason)->toBe('The sack is reserved for the wedding')
        ->and($rejected->decided_by_user_id)->toBe($this->admin->id)
        ->and($rejected->decided_at)->not->toBeNull()
        ->and($rejected->out_movement_id)->toBeNull()
        ->and(DB::table('stock_movements')->count())->toBe($count)
        ->and(ledgerOracle($this->branch->id))->toBe($before);
})->group('contract:production->inventory', 'contract');

it('writes nothing on a second confirm of a confirmed transfer', function () {
    stockIn($this->flour, $this->store, '8000');
    $transfer = app(Transfers::class)->request($this->flour, $this->kitchen, '2000', (string) Str::uuid());
    $first = app(Transfers::class)->confirm($transfer);
    $count = DB::table('stock_movements')->count();
    $audit = DB::table('audit_log')->count();

    $second = app(Transfers::class)->confirm($transfer);

    expect($second->status->value)->toBe('confirmed')
        ->and($second->out_movement_id)->toBe($first->out_movement_id)
        ->and($second->version)->toBe($first->version)
        ->and(DB::table('stock_movements')->count())->toBe($count, 'one pair of rows, however often it is confirmed')
        ->and(DB::table('audit_log')->count())->toBe($audit)
        ->and(stockRow($this->flour, $this->store))->on_hand->toBe('6000.0000')
        ->and(stockRow($this->flour, $this->kitchen))->on_hand->toBe('2000.0000');
})->group('contract:production->inventory', 'contract');
