<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Facts as InventoryFacts;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Orders\Contracts\Facts as OrderFacts;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\Facts as PaymentFacts;
use App\Modules\Payments\Models\Payment;
use App\Modules\Production\Contracts\Facts as ProductionFacts;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Shifts\Contracts\Facts as ShiftFacts;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * Contract: analytics -> facts (phase-4 plan sections 2 and 6, step 4.1a; manifest contracts["analytics->facts"]).
 *
 * Written BEFORE `App\Modules\Analytics` exists (it does not until step 4.4a) and RED until step 4.1c builds the ports. Analytics reads the
 * other modules ONLY through provider-owned `Facts` classes in `<Module>\Contracts`, each a `final` class built per use returning
 * readonly rows through a generator over a chunked, ordered query:
 *
 *   Orders\Contracts\Facts::sales(LocalDay)/lines(LocalDay)   Payments\Contracts\Facts::payments(LocalDay)/refunds(LocalDay)
 *   Production\Contracts\Facts::batches(LocalDay)             Shifts\Contracts\Facts::instances(LocalDay)
 *   Inventory\Contracts\Facts::movements(LocalDay)/closingBalances(string $day)/stockOuts(LocalDay)/unitCosts(string $day)
 *
 * `App\Shared\Time\LocalDay::between('2026-11-20', '2026-11-21')` is an inclusive range of LOCAL dates (Africa/Addis_Ababa, UTC+3).
 * Every row has toArray() with: ids as ints, `day` as 'YYYY-MM-DD' (the local day of the port's clock), money as decimal strings with scale 2,
 * quantities with scale 4. The clocks: orders `settled_at`, payments `received_at`, refunds `refunded_at`, batches `finished_at`,
 * movements `created_at`, shifts the local dates of opening and lock. The test builds its rows with factories at fixed instants (the
 * cases about the day boundary are the point), so it needs no module code except the provider's port.
 */

const FACTS_GROUP = 'contract:analytics->facts';
const DAY_TZ = 'Africa/Addis_Ababa';

uses()->group(FACTS_GROUP);

/** @return list<array<string, mixed>> */
function factRows(iterable $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = $row->toArray();
    }

    return $out;
}

function isScale(string $value, int $scale): bool
{
    return preg_match('/^-?\d+\.\d{'.$scale.'}$/', $value) === 1;
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    // 2026-11-20 23:59:59 local is 20:59:59Z; 2026-11-21 00:00:00 local is 21:00:00Z
    $this->lastSecond = CarbonImmutable::parse('2026-11-20 20:59:59', 'UTC');
    $this->firstSecond = CarbonImmutable::parse('2026-11-20 21:00:00', 'UTC');
    $this->both = LocalDay::between('2026-11-20', '2026-11-21');
    $this->order = fn (string $total, CarbonImmutable $settledAt, OrderStatus $status = OrderStatus::Closed, ?Branch $branch = null): Order => Order::factory()->create([
        'branch_id' => ($branch ?? $this->branch)->id, 'status' => $status, 'subtotal' => $total, 'total' => $total, 'paid_total' => $total,
        'settled_at' => $settledAt, 'created_at' => $settledAt->subMinutes(10),
    ]);
});

it('Orders\Facts::sales: an empty range is [] (never null), the day boundary is local, ends are inclusive, only settled orders are read, branches are isolated', function () {
    expect(factRows((new OrderFacts)->sales($this->both)))->toBe([]);

    $late = ($this->order)('100.00', $this->lastSecond);
    $early = ($this->order)('250.50', $this->firstSecond);
    ($this->order)('90.00', $this->lastSecond, OrderStatus::Voided);
    ($this->order)('90.00', $this->lastSecond, OrderStatus::Cancelled);
    ($this->order)('90.00', $this->lastSecond, OrderStatus::Sent);
    $other = Branch::factory()->create();
    ($this->order)('77.00', $this->lastSecond, OrderStatus::Closed, $other);

    $rows = factRows((new OrderFacts)->sales($this->both));
    expect(array_column($rows, 'order_id'))->toBe([$late->id, $early->id], 'ordered by (day, id), no voided/cancelled/open order, no other branch')
        ->and(array_column($rows, 'day'))->toBe(['2026-11-20', '2026-11-21'], 'bucketed by Africa/Addis_Ababa: 23:59:59 local is the 20th, 00:00:00 the 21st')
        ->and($rows[0]['total'])->toBe('100.00')
        ->and($rows[1]['total'])->toBe('250.50')
        ->and(isScale($rows[0]['total'], 2))->toBeTrue()
        ->and($rows[0]['order_id'])->toBeInt();
    // both ends of the range are inclusive local dates
    expect(factRows((new OrderFacts)->sales(LocalDay::between('2026-11-21', '2026-11-21'))))->toHaveCount(1)
        ->and(factRows((new OrderFacts)->sales(LocalDay::between('2026-11-20', '2026-11-20'))))->toHaveCount(1);
});

it('Orders\Facts::lines: the lines of the settled orders of the range with qty at scale 4 and line totals at scale 2', function () {
    expect(factRows((new OrderFacts)->lines($this->both)))->toBe([]);
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $this->travelTo($this->lastSecond->addDays(30));
    $order = $f->send([$m->line($m->croissant, '2')]);
    $pay = fn () => app(Orders::class)->totals($order['id']);
    Order::query()->whereKey($order['id'])->update(['status' => OrderStatus::Closed, 'settled_at' => $this->lastSecond, 'paid_total' => $pay()->total]);

    $rows = factRows((new OrderFacts)->lines($this->both));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['day'])->toBe('2026-11-20')
        ->and(isScale($rows[0]['qty'], 4))->toBeTrue()
        ->and(isScale($rows[0]['line_total'], 2))->toBeTrue()
        ->and($rows[0]['item_id'])->toBeInt();
});

it('Payments\Facts::payments: bucketed by received_at, voided payments excluded, scale 2, ordered by (day, id), branch isolated', function () {
    expect(factRows((new PaymentFacts)->payments($this->both)))->toBe([]);
    $order = ($this->order)('100.00', $this->lastSecond);
    $make = fn (array $attributes): Payment => Payment::factory()->create($attributes + ['branch_id' => $this->branch->id, 'order_id' => $order->id]);
    $late = $make(['received_at' => $this->lastSecond, 'amount' => '60.00']);
    $early = $make(['received_at' => $this->firstSecond, 'amount' => '40.00']);
    $make(['received_at' => $this->lastSecond, 'amount' => '5.00', 'status' => 'voided']);
    $otherBranch = Branch::factory()->create();
    Payment::factory()->create(['branch_id' => $otherBranch->id, 'received_at' => $this->lastSecond]);

    $rows = factRows((new PaymentFacts)->payments($this->both));

    expect(array_column($rows, 'payment_id'))->toBe([$late->id, $early->id])
        ->and(array_column($rows, 'day'))->toBe(['2026-11-20', '2026-11-21'])
        ->and($rows[0]['amount'])->toBe('60.00')
        ->and($rows[0]['channel_code'])->toBe('cash')
        ->and(array_key_exists('has_receipt', $rows[0]))->toBeTrue();
});

it('Payments\Facts::refunds: bucketed by refunded_at; empty is []; a refund read through the port carries channel, amount and its payment', function () {
    expect(factRows((new PaymentFacts)->refunds($this->both)))->toBe([]);

    // a real refund needs the Phase 4 refund path (Orders::refund, step 4.1b); the money side row is written the way Payments will (4.3d)
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant)]);
    $f->act('served', $f->lineOf($order, $m->croissant)['id'], $f->waiter)->assertOk();
    $payment = Payment::factory()->create(['branch_id' => $f->branch->id, 'order_id' => $order['id'], 'amount' => $order['totals']['total'], 'received_at' => $this->lastSecond]);
    app(Orders::class)->refund($order['id'], 'test', $f->manager, [], [], (string) Str::uuid());
    $orderRefundId = (int) DB::table('order_refunds')->where('order_id', $order['id'])->value('id');
    DB::table('payment_refunds')->insert([
        'branch_id' => $f->branch->id, 'client_uuid' => (string) Str::uuid(), 'order_id' => $order['id'], 'order_refund_id' => $orderRefundId,
        'payment_id' => $payment->id, 'channel_id' => $payment->channel_id, 'amount' => $order['totals']['total'], 'refunded_by_user_id' => $f->manager->id,
        'approved_by_user_id' => $f->manager->id, 'refunded_at' => $this->firstSecond, 'created_at' => $this->firstSecond,
    ]);

    $rows = factRows((new PaymentFacts)->refunds($this->both));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['day'])->toBe('2026-11-21')
        ->and($rows[0]['payment_id'])->toBe($payment->id)
        ->and($rows[0]['amount'])->toBe($order['totals']['total']);
});

it('Production\Facts::batches: bucketed by finished_at, only finished or confirmed batches, quantities at scale 4', function () {
    expect(factRows((new ProductionFacts)->batches($this->both)))->toBe([]);
    $late = ProductionBatch::factory()->finished()->create(['branch_id' => $this->branch->id, 'finished_at' => $this->lastSecond]);
    $early = ProductionBatch::factory()->finished()->create(['branch_id' => $this->branch->id, 'finished_at' => $this->firstSecond]);
    ProductionBatch::factory()->create(['branch_id' => $this->branch->id]); // still started

    $rows = factRows((new ProductionFacts)->batches($this->both));

    expect(array_column($rows, 'batch_id'))->toBe([$late->id, $early->id])
        ->and(array_column($rows, 'day'))->toBe(['2026-11-20', '2026-11-21'])
        ->and(isScale($rows[0]['produced_qty'], 4))->toBeTrue()
        ->and(isScale($rows[0]['waste_qty'], 4))->toBeTrue();
});

it('Inventory\Facts::movements: bucketed by created_at in local days with qty at scale 4, ordered by (day, id)', function () {
    expect(factRows((new InventoryFacts)->movements($this->both)))->toBe([]);
    $kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->travelTo($this->lastSecond);
    $a = stockIn($flour, $kitchen, '5');
    $this->travelTo($this->firstSecond);
    $b = stockIn($flour, $kitchen, '3');

    $rows = factRows((new InventoryFacts)->movements($this->both));

    expect(array_column($rows, 'movement_id'))->toBe([$a->id, $b->id])
        ->and(array_column($rows, 'day'))->toBe(['2026-11-20', '2026-11-21'])
        ->and($rows[0]['qty'])->toBe('5.0000')
        ->and($rows[0]['reason'])->toBe('purchase_in');
});

it('Inventory\Facts::closingBalances(day) equals Stock::balanceAt at the end of the local day, for every item with stock (oracle)', function () {
    $kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $sugar = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $this->travelTo($this->lastSecond->subHours(2));
    stockIn($flour, $kitchen, '10');
    stockIn($sugar, $kitchen, '4');
    $this->travelTo($this->lastSecond);
    stockIn($flour, $kitchen, '-3', MovementReason::Waste);
    $this->travelTo($this->firstSecond); // the next local day: not part of the 20th's closing
    stockIn($flour, $kitchen, '100');

    $rows = factRows((new InventoryFacts)->closingBalances('2026-11-20'));

    $endOfDay = $this->firstSecond->subSecond();
    $byItem = [];
    foreach ($rows as $row) {
        $byItem[$row['item_id']] = $row;
    }
    expect(array_keys($byItem))->toEqualCanonicalizing([$flour->id, $sugar->id]);
    foreach ([$flour, $sugar] as $item) {
        expect($byItem[$item->id]['on_hand'])->toBe(app(Stock::class)->balanceAt($item, $kitchen, $endOfDay)->onHand->toString());
    }
    expect($byItem[$flour->id]['on_hand'])->toBe('7.0000')->and(factRows((new InventoryFacts)->closingBalances('2026-10-01')))->toBe([]);
});

it('Inventory\Facts::stockOuts: the out episodes that began on the days of the range', function () {
    expect(factRows((new InventoryFacts)->stockOuts($this->both)))->toBe([]);
    $kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    DB::table('stock_alert_states')->insert([
        'branch_id' => $this->branch->id, 'item_id' => $flour->id, 'location_id' => $kitchen->id, 'location_key' => $kitchen->id, 'type' => 'out',
        'is_active' => null, 'active_since' => $this->lastSecond, 'cleared_at' => $this->firstSecond, 'available_at_start' => '0.0000',
        'created_at' => $this->lastSecond, 'updated_at' => $this->firstSecond,
    ]);

    $rows = factRows((new InventoryFacts)->stockOuts($this->both));

    expect($rows)->toHaveCount(1)->and($rows[0]['day'])->toBe('2026-11-20')->and($rows[0]['item_id'])->toBe($flour->id);
});

it('Inventory\Facts::unitCosts(day) is the item_cost_history row in force at the end of that local day', function () {
    $flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $history = fn (CarbonImmutable $at, string $costAfter) => ItemCostHistory::factory()->create([
        'branch_id' => $this->branch->id, 'item_id' => $flour->id, 'cost_after' => $costAfter, 'created_at' => $at,
    ]);
    $history($this->lastSecond->subDay(), '5.0000');
    $history($this->lastSecond, '6.0000');   // still the 20th
    $history($this->firstSecond, '9.0000');  // the 21st

    $rows = factRows((new InventoryFacts)->unitCosts('2026-11-20'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['item_id'])->toBe($flour->id)
        ->and($rows[0]['unit_cost'])->toBe('6.0000')
        ->and(factRows((new InventoryFacts)->unitCosts('2026-11-19'))[0]['unit_cost'])->toBe('5.0000');
});

it('Shifts\Facts::instances: a shift once, with date, opened_at, closed_at and locked_at, for a range holding any local day of its life (a Night shift 22:00 to 06:00)', function () {
    expect(factRows((new ShiftFacts)->instances($this->both)))->toBe([]);
    $station = Station::factory()->create(['branch_id' => $this->branch->id]);
    $night = ShiftInstance::factory()->create([
        'branch_id' => $this->branch->id, 'station_id' => $station->id, 'status' => ShiftStatus::Locked, 'date' => '2026-11-20',
        'opened_at' => CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC'),  // 22:00 local on the 20th
        'locked_at' => CarbonImmutable::parse('2026-11-21 03:30:00', 'UTC'),  // 06:30 local on the 21st
    ]);

    foreach ([LocalDay::between('2026-11-20', '2026-11-20'), LocalDay::between('2026-11-21', '2026-11-21'), $this->both] as $range) {
        $rows = factRows((new ShiftFacts)->instances($range));
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['shift_id'])->toBe($night->id)
            ->and($rows[0]['date'])->toBe('2026-11-20')
            ->and($rows[0]['opened_at'])->not->toBeNull()
            ->and($rows[0]['locked_at'])->not->toBeNull()
            ->and(array_key_exists('closed_at', $rows[0]))->toBeTrue();
    }
    expect(factRows((new ShiftFacts)->instances(LocalDay::between('2026-11-22', '2026-11-22'))))->toBe([]);
});
