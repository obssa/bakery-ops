<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Contracts\ActorDirectory;
use App\Modules\Identity\Contracts\Stations as IdentityStations;
use App\Modules\Inventory\Contracts\Facts as InventoryFacts;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Orders\Contracts\Facts as OrderFacts;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Payments\Contracts\Facts as PaymentFacts;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Production\Contracts\Facts as ProductionFacts;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Shifts\Contracts\Facts as ShiftFacts;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Enums\StationCode;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;
use Tests\Support\OrderRefundRows;

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

    // a real refund through the Phase 4 refund path (Orders::refund, step 4.1b); the money side row is written the way Payments will (4.3d)
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant)]);
    $f->act('served', $f->lineOf($order, $m->croissant)['id'], $f->waiter)->assertOk();
    $payment = Payment::factory()->create(['branch_id' => $f->branch->id, 'order_id' => $order['id'], 'amount' => $order['totals']['total'], 'received_at' => $this->lastSecond]);
    withActor($f->cashier->id);
    DB::transaction(fn () => event(new PaymentRecorded($f->branch->id, $payment->id, $order['id'], 'cash', $order['totals']['total'], '0.00')));
    $orderRefundId = app(Orders::class)->refund($order['id'], 'customer returned it', $f->manager, [], (string) Str::uuid())->id;
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
    // one cake room for the three requests (the factory would make a second station with the same code per branch)
    $station = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $request = fn (): int => ProductionRequest::factory()->status(RequestStatus::InProgress)->create(['branch_id' => $this->branch->id, 'station_id' => $station->id])->id;
    $late = ProductionBatch::factory()->finished()->create(['branch_id' => $this->branch->id, 'request_id' => $request(), 'finished_at' => $this->lastSecond]);
    $early = ProductionBatch::factory()->finished()->create(['branch_id' => $this->branch->id, 'request_id' => $request(), 'finished_at' => $this->firstSecond]);
    ProductionBatch::factory()->create(['branch_id' => $this->branch->id, 'request_id' => $request()]); // still started

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

/*
 * Step 4.4a: what the summary tables need beyond the ten cases of 4.1a/4.1c. Each is ADDITIVE to a port (a new method, or a new field
 * at the end of a row), committed red before the provider work, with `app/Modules/Analytics` still empty (the contract-first rule).
 *   Orders\Facts::voids(range)         voided orders on the day of voided_at, cancelled ones on the day of cancelled_at (daily_sales.voided_*,
 *                                      cancelled_count; daily_user_metrics.voids_count by who asked)
 *   Orders\Facts::stationLines(range)  station lines on every day one was readied, served or rejected (prep times, lines_served, lines_rejected,
 *                                      tickets; the clocks of ADR 0014 are the line's own, not the order's settled_at)
 *   PaymentFact::duplicateReference    the duplicate rule of Payments (10.8 R-B), so daily_channel_totals.duplicate_reference_count needs no reference
 *   RefundFact::orderRefundId          the refund a money row belongs to (daily_sales.refund_count counts refunds, not their payment rows)
 *   StockOutFact::stationId            the station of the location (daily_station_metrics.stockout_count)
 *   ShiftFact::varianceLineCount / cashVariance  the final counts of the shift (daily_station_metrics.variance_line_count, cash_variance_total)
 *   Catalog ItemSummary::categoryId    daily_item_metrics.category_id
 */

it('Orders\Facts::voids: voided orders on the local day of voided_at, cancelled ones on the day of cancelled_at, with the total and who asked', function () {
    expect(factRows((new OrderFacts)->voids($this->both)))->toBe([]);
    $voided = Order::factory()->create([
        'branch_id' => $this->branch->id, 'status' => OrderStatus::Voided, 'subtotal' => '80.00', 'total' => '80.00',
        'voided_at' => $this->lastSecond, 'voided_by_user_id' => $this->admin->id, 'void_reason' => 'wrong table',
    ]);
    $cancelled = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::Cancelled, 'cancelled_at' => $this->firstSecond]);
    ($this->order)('90.00', $this->lastSecond); // a sale is not a void
    $other = Branch::factory()->create();
    Order::factory()->create(['branch_id' => $other->id, 'status' => OrderStatus::Voided, 'voided_at' => $this->lastSecond]);

    $rows = factRows((new OrderFacts)->voids($this->both));

    expect(array_column($rows, 'order_id'))->toBe([$voided->id, $cancelled->id], 'ordered by (day, id), no sale, no other branch')
        ->and(array_column($rows, 'day'))->toBe(['2026-11-20', '2026-11-21'], 'each on its own clock, bucketed by Africa/Addis_Ababa')
        ->and(array_column($rows, 'status'))->toBe(['voided', 'cancelled'])
        ->and($rows[0]['total'])->toBe('80.00')
        ->and($rows[0]['voided_by_user_id'])->toBe($this->admin->id)
        ->and($rows[1]['voided_by_user_id'])->toBeNull()
        ->and(array_key_exists('owner_user_id', $rows[0]))->toBeTrue();
    expect(factRows((new OrderFacts)->voids(LocalDay::between('2026-11-21', '2026-11-21'))))->toHaveCount(1);
});

it('Orders\Facts::stationLines: a station line on every local day on which it was readied, served or rejected, whatever its order became', function () {
    expect(factRows((new OrderFacts)->stationLines($this->both)))->toBe([]);
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => OrderStatus::Voided, 'voided_at' => $this->firstSecond]);
    $line = fn (array $attributes): OrderLine => OrderLine::factory()->atStation($kitchen)->create($attributes + ['order_id' => $order->id]);
    $acrossMidnight = $line([
        'status' => LineStatus::Served, 'accepted_at' => $this->lastSecond->subMinutes(12), 'ready_at' => $this->lastSecond,
        'served_at' => $this->firstSecond, 'prepared_by_user_id' => $this->admin->id, 'qty' => '2',
    ]);
    $rejected = $line(['status' => LineStatus::Rejected, 'rejected_at' => $this->firstSecond, 'reject_reason' => 'out_of_stock']);
    $line([]); // queued: nothing happened to it yet
    OrderLine::factory()->create(['order_id' => $order->id, 'status' => LineStatus::Ready, 'ready_at' => $this->lastSecond]); // display case: no station
    $other = Branch::factory()->create();
    $otherKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $other->id]);
    OrderLine::factory()->atStation($otherKitchen)->create([
        'order_id' => Order::factory()->create(['branch_id' => $other->id])->id, 'status' => LineStatus::Ready, 'ready_at' => $this->lastSecond,
    ]);

    $rows = factRows((new OrderFacts)->stationLines($this->both));

    expect(array_map(fn (array $r): array => [$r['line_id'], $r['day']], $rows))
        ->toBe([[$acrossMidnight->id, '2026-11-20'], [$acrossMidnight->id, '2026-11-21'], [$rejected->id, '2026-11-21']], 'once per day it touched, ordered (day, id)')
        ->and($rows[0]['station_id'])->toBe($kitchen->id)
        ->and($rows[0]['order_id'])->toBe($order->id)
        ->and($rows[0]['ready_at'])->toBe('2026-11-20T20:59:59Z')
        ->and($rows[0]['served_at'])->toBe('2026-11-20T21:00:00Z')
        ->and($rows[0]['accepted_at'])->toBe('2026-11-20T20:47:59Z')
        ->and($rows[0]['prepared_by_user_id'])->toBe($this->admin->id)
        ->and($rows[0]['qty'])->toBe('2.0000')
        ->and($rows[2]['rejected_at'])->toBe('2026-11-20T21:00:00Z')
        ->and($rows[2]['status'])->toBe('rejected');
    expect(factRows((new OrderFacts)->stationLines(LocalDay::between('2026-11-20', '2026-11-20'))))->toHaveCount(1);
});

it('Payments\Facts::payments flags a duplicate reference: another live payment of the branch on the same channel with the same normalised reference', function () {
    $order = ($this->order)('100.00', $this->lastSecond);
    $channel = fn (string $code): int => (int) PaymentChannel::query()->where('code', $code)->value('id');
    $make = fn (array $attributes): Payment => Payment::factory()->create($attributes + [
        'branch_id' => $this->branch->id, 'order_id' => $order->id, 'received_at' => $this->lastSecond, 'amount' => '10.00',
    ]);
    $first = $make(['channel_id' => $channel('telebirr'), 'reference_no' => 'FT-123', 'reference_norm' => 'FT123']);
    $second = $make(['channel_id' => $channel('telebirr'), 'reference_no' => 'ft 123', 'reference_norm' => 'FT123']);
    $alone = $make(['channel_id' => $channel('telebirr'), 'reference_no' => 'FT999', 'reference_norm' => 'FT999']);
    $otherChannel = $make(['channel_id' => $channel('cbe_birr'), 'reference_no' => 'FT999', 'reference_norm' => 'FT999']);
    $make(['channel_id' => $channel('cbe_birr'), 'reference_no' => 'FT-555', 'reference_norm' => 'FT555', 'status' => 'voided']);
    $voidedTwin = $make(['channel_id' => $channel('cbe_birr'), 'reference_no' => 'FT555', 'reference_norm' => 'FT555']);
    $cash = $make([]);

    $flags = [];
    foreach (factRows((new PaymentFacts)->payments($this->both)) as $row) {
        $flags[$row['payment_id']] = $row['duplicate_reference'];
    }

    expect($flags)->toBe([
        $first->id => true, $second->id => true, $alone->id => false, $otherChannel->id => false, $voidedTwin->id => false, $cash->id => false,
    ], 'same channel and normalised reference; a voided payment is nobody else\'s duplicate and is not read');
});

it('Payments\Facts::refunds: each money row names the refund it belongs to', function () {
    $order = ($this->order)('100.00', $this->lastSecond);
    $payment = Payment::factory()->create(['branch_id' => $this->branch->id, 'order_id' => $order->id, 'amount' => '100.00', 'received_at' => $this->lastSecond]);
    $orderRefundId = OrderRefundRows::make($this->branch->id, $order->id, $this->admin->id, '40.00');
    DB::table('payment_refunds')->insert([
        'branch_id' => $this->branch->id, 'client_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'order_refund_id' => $orderRefundId,
        'payment_id' => $payment->id, 'channel_id' => $payment->channel_id, 'amount' => '40.00', 'refunded_by_user_id' => $this->admin->id,
        'approved_by_user_id' => $this->admin->id, 'refunded_at' => $this->lastSecond, 'created_at' => $this->lastSecond,
    ]);

    $rows = factRows((new PaymentFacts)->refunds($this->both));

    expect($rows)->toHaveCount(1)->and($rows[0]['order_refund_id'])->toBe($orderRefundId)->and($rows[0]['amount'])->toBe('40.00');
});

it('Inventory\Facts::stockOuts: an episode names the station of its location (null for a place no station owns)', function () {
    $station = Station::factory()->create(['branch_id' => $this->branch->id]);
    $kitchen = stockLocation($this->branch, LocationType::Kitchen, $station);
    $store = stockLocation($this->branch, LocationType::Store);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    foreach ([$kitchen, $store] as $place) {
        DB::table('stock_alert_states')->insert([
            'branch_id' => $this->branch->id, 'item_id' => $flour->id, 'location_id' => $place->id, 'location_key' => $place->id, 'type' => 'out',
            'is_active' => 1, 'active_since' => $this->lastSecond, 'cleared_at' => null, 'available_at_start' => '0.0000',
            'created_at' => $this->lastSecond, 'updated_at' => $this->lastSecond,
        ]);
    }

    $rows = factRows((new InventoryFacts)->stockOuts($this->both));

    expect(array_column($rows, 'location_id'))->toBe([$kitchen->id, $store->id])
        ->and(array_column($rows, 'station_id'))->toBe([$station->id, null]);
});

it('Shifts\Facts::instances: a shift carries the variance lines and the cash variance of its final counts (a draft, rejected or superseded count is left out)', function () {
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $shelf = stockLocation($this->branch, LocationType::Kitchen, $kitchen);
    $fridge = stockLocation($this->branch, LocationType::Kitchen, $kitchen);
    $lived = ['date' => '2026-11-20', 'opened_at' => $this->lastSecond->subHours(10), 'locked_at' => $this->lastSecond->subHour()];
    $shift = ShiftInstance::factory()->locked()->create(['branch_id' => $this->branch->id, 'station_id' => $kitchen->id] + $lived);
    $item = fn (): Item => Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);
    $closing = StockCount::factory()->forShift($shift, $shelf->id)->closing()->status(CountStatus::Approved)->create();
    StockCountLine::factory()->forCount($closing, $item()->id)->counted('9', '10')->create();
    StockCountLine::factory()->forCount($closing, $item()->id)->counted('5', '5')->create();
    StockCountLine::factory()->forCount($closing, $item()->id)->counted('3', '2.5')->create();
    $draft = StockCount::factory()->forShift($shift, $fridge->id)->opening()->create();
    StockCountLine::factory()->forCount($draft, $item()->id)->counted('1', '2')->create();
    $rejected = StockCount::factory()->forShift($shift, $fridge->id)->closing()->status(CountStatus::Rejected)->create();
    StockCountLine::factory()->forCount($rejected, $item()->id)->counted('1', '2')->create();

    $till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $this->branch->id]);
    $drawer = stockLocation($this->branch, LocationType::CashDrawer, $till);
    $tillShift = ShiftInstance::factory()->locked()->create(['branch_id' => $this->branch->id, 'station_id' => $till->id] + $lived);
    $drawerCount = StockCount::factory()->forShift($tillShift, $drawer->id)->closing()->create();
    DB::table('cash_declarations')->insert([
        'branch_id' => $this->branch->id, 'count_id' => $drawerCount->id, 'shift_instance_id' => $tillShift->id, 'denominations' => '[]',
        'counted_total' => '95.00', 'expected_total' => '100.00', 'variance_total' => '-5.00', 'currency' => 'ETB',
        'created_at' => $this->lastSecond, 'updated_at' => $this->lastSecond,
    ]);
    DB::table('stock_counts')->where('id', $drawerCount->id)->update([
        'status' => 'approved', 'counted_by_user_id' => $this->admin->id, 'snapshot_at' => $this->lastSecond, 'submitted_at' => $this->lastSecond,
    ]);
    $bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $quiet = ShiftInstance::factory()->locked()->create(['branch_id' => $this->branch->id, 'station_id' => $bar->id] + $lived);

    $byShift = [];
    foreach (factRows((new ShiftFacts)->instances(LocalDay::of('2026-11-20'))) as $row) {
        $byShift[$row['shift_id']] = [$row['variance_line_count'], $row['cash_variance']];
    }

    expect($byShift)->toBe([$shift->id => [2, null], $tillShift->id => [0, '-5.00'], $quiet->id => [0, null]]);
});

it('Catalog Items::summaries names the category of each item (daily_item_metrics groups products by it)', function () {
    $category = Category::factory()->product()->create(['branch_id' => $this->branch->id]);
    $croissant = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'category_id' => $category->id]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);

    $summaries = app(Items::class)->summaries([$croissant->id, $flour->id]);

    expect($summaries[$croissant->id]->categoryId)->toBe($category->id)
        ->and($summaries[$flour->id]->categoryId)->toBeNull();
});

/*
 * Step 4.4c: the dashboards name what the summaries count (phase-4 plan section 4.6) through the providers' contracts only, and in one query
 * each, so a dashboard stays inside its query budget (DashboardBudgetTest): channels through the Payments port, items (with their base unit
 * and category) through Catalog, people and stations through Identity's ActorDirectory. A row that was removed since it was counted is still
 * named, never another branch's.
 */

it('Payments\Facts::channels names the channels of the ids, a switched-off or removed one too, never another branch\'s, in one query', function () {
    expect((new PaymentFacts)->channels([]))->toBe([]);
    $cash = PaymentChannel::query()->where('code', 'cash')->firstOrFail();
    $telebirr = PaymentChannel::query()->where('code', 'telebirr')->firstOrFail();
    $telebirr->forceFill(['is_enabled' => false])->save();
    $awash = PaymentChannel::query()->where('code', 'awash')->firstOrFail();
    $awash->delete();
    $other = Branch::factory()->create();
    $foreign = (int) PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $other->id)->where('code', 'cash')->value('id');
    actInBranch($this->branch);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $rows = (new PaymentFacts)->channels([$awash->id, $telebirr->id, $cash->id, $foreign, 999999, $cash->id]);

    expect($queries)->toBe(1)
        ->and(array_keys($rows))->toBe([$cash->id, $telebirr->id, $awash->id])
        ->and($rows[$cash->id]->toArray())->toBe(['channel_id' => $cash->id, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'is_enabled' => true, 'is_removed' => false])
        ->and($rows[$telebirr->id]->isEnabled)->toBeFalse()
        ->and($rows[$awash->id]->isRemoved)->toBeTrue()
        ->and($rows[$awash->id]->nameEn)->toBe('Awash Bank');
});

it('Catalog Items::labels names the items of the ids with their kind, base unit, category and the new flag, a deleted one too, never another branch\'s, in one query', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 20:59:59', 'UTC')); // 23:59:59 local on the 22nd
    expect(app(Items::class)->labels([]))->toBe([]);
    $category = Category::factory()->product()->create(['branch_id' => $this->branch->id, 'name_en' => 'Pastry', 'name_am' => 'ኬክ']);
    $croissant = Item::factory()->product(Fulfilment::ToStock)->create([
        'branch_id' => $this->branch->id, 'category_id' => $category->id, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'is_new_until' => '2026-11-22',
    ]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'is_new_until' => '2026-11-21']);
    $gone = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    $gone->delete();
    $category->delete();
    $foreign = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => Branch::factory()->create()->id]);
    actInBranch($this->branch);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $labels = app(Items::class)->labels([$gone->id, $flour->id, $croissant->id, $foreign->id, 999999]);

    expect($queries)->toBe(1)
        ->and(array_keys($labels))->toBe([$croissant->id, $flour->id, $gone->id])
        ->and($labels[$croissant->id]->toArray())->toBe([
            'id' => $croissant->id, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'kind' => 'product', 'unit' => 'pcs',
            'category' => ['id' => $category->id, 'name_en' => 'Pastry', 'name_am' => 'ኬክ'], 'is_new' => true, 'is_active' => true,
        ])
        ->and($labels[$flour->id]->toArray())->toMatchArray(['kind' => 'ingredient', 'unit' => 'g', 'category' => null, 'is_new' => false, 'is_active' => true])
        ->and($labels[$gone->id]->isActive)->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-22 21:00:00', 'UTC')); // local midnight: no longer new
    expect(app(Items::class)->labels([$croissant->id])[$croissant->id]->isNew)->toBeFalse();
});

it('Identity ActorDirectory names people (a deleted one too) and stations, never another branch\'s, and lists the stations a person works at', function () {
    $directory = app(ActorDirectory::class);
    expect($directory->names([]))->toBe([])->and($directory->stations([]))->toBe([]);
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id, 'name_en' => 'Kitchen', 'name_am' => 'ማብሰያ ቤት']);
    $bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $closed = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $chef = makeUser($this->branch, 'chef', ['name' => 'Chaltu']);
    app(IdentityStations::class)->replace($chef, [['station_id' => $kitchen->id, 'can_count' => true, 'is_overseer' => false]]);
    $left = makeUser($this->branch, 'waiter', ['name' => 'Abebe']);
    $left->delete();
    $closed->delete();
    $otherBranch = Branch::factory()->create();
    $foreigner = makeUser($otherBranch, 'waiter');
    $foreignStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $otherBranch->id]);
    actInBranch($this->branch);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $names = $directory->names([$left->id, $chef->id, $foreigner->id, 999999]);
    $stations = $directory->stations([$closed->id, $kitchen->id, $foreignStation->id]);

    expect($queries)->toBe(2, 'one query for the people, one for the stations')
        ->and($names)->toBe([$chef->id => 'Chaltu', $left->id => 'Abebe'])
        ->and(array_keys($stations))->toBe([$kitchen->id, $closed->id])
        ->and($stations[$kitchen->id]->toArray())->toBe(['id' => $kitchen->id, 'code' => 'kitchen', 'name_en' => 'Kitchen', 'name_am' => 'ማብሰያ ቤት', 'is_active' => true])
        ->and($stations[$closed->id]->isActive)->toBeFalse()
        ->and($directory->workStationIds($chef->id))->toBe([$kitchen->id])
        ->and($directory->workStationIds($this->admin->id))->toBe([$kitchen->id, $bar->id], 'stations.all: every station of the branch')
        ->and($directory->workStationIds($left->id))->toBe([], 'a person who cannot act works nowhere')
        ->and($directory->workStationIds($foreigner->id))->toBe([]);
});
