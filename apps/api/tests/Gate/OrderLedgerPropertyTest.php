<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Actions\AcceptLine;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\MarkLineReady;
use App\Modules\Orders\Actions\RejectLine;
use App\Modules\Orders\Actions\SendOrder;
use App\Modules\Orders\Actions\ServeLine;
use App\Modules\Orders\Actions\VoidOrder;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Enums\RejectReason;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Exceptions\SendRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineInput;
use App\Modules\Payments\Actions\RecordPayment;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\OrdersMenu;
use Tests\Support\Shifts;

/*
 * Phase 1 gate, property criterion (phase-1 plan section 7): random sequences of at most 50 operations (send, accept,
 * ready, reject, served, void, pay) over a small menu with shared ingredients (the chicken sandwich and the chicken wrap
 * both take chicken, lettuce and butter at the kitchen; the latte and the large latte share the bar's milk), driven
 * through the module code the API uses. After every operation:
 *   - the ledger oracle agrees: sum(movements) == stock_balances == item_stock_view;
 *   - `reserved` on every balance row equals the reservation rows of the lines that are still open (a station line
 *     queued or accepted; a counter line waiting for its sale in an order not yet paid, closed or voided);
 *   - `available` (on_hand - reserved) is never below zero, nor on_hand or reserved themselves;
 *   - a refused send (shortfall) writes nothing.
 * At the end every closed order's movements are `reservation -> production_consume` per station line and
 * `reservation -> sale` per counter line (`reservation -> reservation_release` for a rejected line).
 * 100 seeds; ORDER_LEDGER_PROPERTY_SEED=<n> replays one. The seed is in every failure message.
 */

uses(RefreshDatabase::class);

/** @return array<string, array{0: int}> */
function orderLedgerSeeds(): array
{
    $one = getenv('ORDER_LEDGER_PROPERTY_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 8101 + $i * 104729, range(1, 100));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

/**
 * The reservation rows of the lines that are still open, "item:location" => qty, summed from the movements of their refs.
 *
 * @return array<string, string>
 */
function openReservations(int $branchId): array
{
    $refs = [];
    $orders = Order::query()->get()->keyBy('id');
    foreach (OrderLine::query()->get() as $line) {
        $order = $orders[$line->order_id];
        if (in_array($order->status, [OrderStatus::Open, OrderStatus::Cancelled], true)) {
            continue;
        }
        $open = $line->station_id !== null
            ? in_array($line->status, [LineStatus::Queued, LineStatus::Accepted], true)
            : in_array($line->status, [LineStatus::Ready, LineStatus::Served], true)
                && ! in_array($order->status, [OrderStatus::Paid, OrderStatus::Closed, OrderStatus::Voided], true);
        if ($open) {
            $refs[] = $line->reservation_ref;
        }
    }

    $held = [];
    foreach (DB::table('stock_movements')->where('branch_id', $branchId)->where('reason', 'reservation')->whereIn('reservation_ref', $refs)->get() as $row) {
        $key = $row->item_id.':'.($row->from_location_id ?? $row->to_location_id);
        $held[$key] = ($held[$key] ?? BigDecimal::zero())->plus((string) $row->qty);
    }

    return array_map(fn (BigDecimal $q): string => (string) $q->toScale(4), $held);
}

it('keeps the ledger honest through random order sequences: oracle, reserved, available and the closed orders\' movements', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    $b = $branch->id;
    $admin = makeUser($branch, 'admin');
    withActor($admin->id);
    $m = OrdersMenu::build($branch);
    $waiter = makeUser($branch, 'waiter');
    $chef = makeUser($branch, 'chef');
    $barista = makeUser($branch, 'barista');
    $manager = makeUser($branch, 'manager');
    $cashier = makeUser($branch, 'cashier');

    // shared ingredients: a second kitchen product on the sandwich's chicken, lettuce and butter
    $wrap = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => 'Chicken wrap', 'name_am' => 'የዶሮ ጥቅል']);
    $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $wrap->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
    foreach ([[$m->chicken, '90', 'g'], [$m->lettuce, '30', 'g'], [$m->butter, '5', 'g']] as [$ingredient, $qty, $unit]) {
        RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id, 'qty' => $qty, 'unit_id' => catalogUnit($unit)->id]);
    }
    app(ItemStations::class)->replace($wrap, [$m->kitchenStation->id]);
    app(PriceHistory::class)->open($wrap, Money::of('180.00'), CarbonImmutable::now()->subDay()->startOfSecond());

    // scarce stock so shortfalls happen: a little of the shared chicken (kg) and of the counter products, sometimes none
    $scarce = [
        [$m->chicken, $m->kitchen, '1000', (string) BigDecimal::of((string) $rng->getInt(0, 1500))->dividedBy(1000, 4)],
        [$m->croissant, $m->display, '5', (string) $rng->getInt(0, 6)],
        [$m->cake, $m->display, '1', (string) $rng->getInt(0, 2)],
        [$m->water, $m->display, '24', (string) $rng->getInt(0, 6)],
    ];
    foreach ($scarce as [$item, $location, $has, $wants]) {
        $delta = BigDecimal::of($wants)->minus($has);
        if (! $delta->isZero()) {
            stockIn($item, $location, (string) $delta->toScale(4), MovementReason::CountAdjustment);
        }
    }

    $create = app(CreateOrder::class);
    $send = app(SendOrder::class);
    $accept = app(AcceptLine::class);
    $ready = app(MarkLineReady::class);
    $reject = app(RejectLine::class);
    $serve = app(ServeLine::class);
    $void = app(VoidOrder::class);
    $pay = app(RecordPayment::class);
    $stationUser = fn (OrderLine $line): User => $line->station_id === $m->barStation->id ? $barista : $chef;
    $products = [$m->sandwich, $wrap, $m->latte, $m->croissant, $m->cake, $m->water];
    $modifiersOf = [$m->sandwich->id => [$m->noOnion, $m->extraChicken, $m->withTomato], $m->latte->id => [$m->large]];

    $ops = $rng->getInt(1, 50);
    $trace = [];
    for ($op = 1; $op <= $ops; $op++) {
        $at = "seed {$seed}, op {$op} (after: ".implode(', ', array_slice($trace, -4)).')';

        $orders = Order::query()->orderBy('id')->get();
        $lines = OrderLine::query()->orderBy('id')->get();
        unset($created);
        $candidates = [['new'], ['new'], ['new']];
        foreach ($orders as $known) {
            $live = in_array($known->status, [OrderStatus::Sent, OrderStatus::InProgress, OrderStatus::Ready, OrderStatus::Served], true);
            if ($known->status === OrderStatus::Open) {
                $candidates[] = ['send', $known->id];
            }
            if ($live && $known->settled_at === null) {
                $candidates[] = ['void', $known->id];
                if (! $known->total->isZero()) {
                    $candidates[] = ['pay', $known->id];
                }
            }
        }
        $statusOf = $orders->mapWithKeys(fn (Order $o): array => [$o->id => $o->status])->all();
        foreach ($lines as $line) {
            if ($statusOf[$line->order_id] === OrderStatus::Open) {
                continue;
            }
            if ($line->station_id !== null && in_array($line->status, [LineStatus::Queued, LineStatus::Accepted], true)) {
                $candidates[] = ['ready', $line->id];
                $candidates[] = ['reject', $line->id];
                if ($line->status === LineStatus::Queued) {
                    $candidates[] = ['accept', $line->id];
                }
            }
            if ($line->status === LineStatus::Ready) {
                $candidates[] = ['served', $line->id];
            }
        }
        [$kind, $id] = $candidates[$rng->getInt(0, count($candidates) - 1)] + [1 => null];
        $trace[] = $id === null ? $kind : "{$kind} #{$id}";
        $before = DB::table('stock_movements')->where('branch_id', $b)->count();

        try {
            switch ($kind) {
                case 'new':
                    $inputs = [];
                    foreach (range(1, $rng->getInt(1, 3)) as $i) {
                        $product = $products[$rng->getInt(0, count($products) - 1)];
                        $mods = [];
                        foreach ($modifiersOf[$product->id] ?? [] as $modifier) {
                            if ($rng->getInt(0, 3) === 0) {
                                $mods[] = $modifier->id;
                            }
                        }
                        $inputs[] = new LineInput($i - 1, (string) Str::uuid(), $product->id, Quantity::of((string) $rng->getInt(1, 3)), $mods, null);
                    }
                    [$created] = $create->handle((string) Str::uuid(), OrderType::Takeaway, null, null, $inputs, $waiter, CreatedVia::WaiterPad);
                    $before = DB::table('stock_movements')->where('branch_id', $b)->count();
                    // fall through: a new cart is sent at once, and stays open when the stock is short
                case 'send':
                    $order = $created ?? Order::query()->findOrFail($id);
                    try {
                        $send->send($order);
                    } catch (SendRefused) {
                        expect(DB::table('stock_movements')->where('branch_id', $b)->count())->toBe($before, "{$at}: a refused send wrote movements")
                            ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::Open, "{$at}: a refused send left the cart open");
                    }
                    break;
                case 'accept':
                    $accept->handle(OrderLine::query()->findOrFail($id), $stationUser(OrderLine::query()->findOrFail($id)));
                    break;
                case 'ready':
                    $ready->handle(OrderLine::query()->findOrFail($id), $stationUser(OrderLine::query()->findOrFail($id)));
                    break;
                case 'reject':
                    $reject->handle(OrderLine::query()->findOrFail($id), $stationUser(OrderLine::query()->findOrFail($id)), RejectReason::OutOfStock, null);
                    break;
                case 'served':
                    $serve->handle(OrderLine::query()->findOrFail($id), $waiter);
                    break;
                case 'void':
                    $void->handle(Order::query()->findOrFail($id), $cashier, $manager, 'Property sequence');
                    break;
                case 'pay':
                    $order = Order::query()->findOrFail($id);
                    // payments.require_shift_for_cash: the cashier takes the cash in the till's running shift
                    app(ActorContext::class)->inShift(Shifts::ensureOpen($m->tillStation));
                    $pay->handle($order->id, (string) Str::uuid(), 'cash', $order->total->amount(), $order->total->amount(), null, $cashier);
                    break;
            }
        } catch (Throwable $e) {
            throw new RuntimeException("{$at}: {$kind} threw ".$e::class.': '.$e->getMessage().($e instanceof OrderError ? ' '.json_encode($e->extra).' order #'.($id ?? 'new') : ''), 0, $e);
        }
        unset($created);

        // the oracle: movements == balances == view
        $oracle = ledgerOracle($b);
        expect($oracle['balances'])->toBe($oracle['movements'], "{$at}: stock_balances != sum(movements)")
            ->and($oracle['view'])->toBe($oracle['movements'], "{$at}: item_stock_view != sum(movements)");

        // no balance below zero anywhere
        $reserved = [];
        foreach (DB::table('stock_balances')->where('branch_id', $b)->get() as $row) {
            $onHand = BigDecimal::of((string) $row->on_hand);
            $held = BigDecimal::of((string) $row->reserved);
            expect($held->isNegative())->toBeFalse("{$at}: reserved < 0 at {$row->item_id}:{$row->location_id}")
                ->and($onHand->minus($held)->isNegative())->toBeFalse("{$at}: available < 0 at {$row->item_id}:{$row->location_id}");
            if (! $held->isZero()) {
                $reserved[$row->item_id.':'.$row->location_id] = (string) $held->toScale(4);
            }
        }

        // reserved == the open lines' reservations
        $expected = openReservations($b);
        ksort($expected);
        ksort($reserved);
        expect($reserved)->toBe(array_filter($expected, fn (string $q): bool => $q !== '0.0000'), "{$at}: reserved != the open lines' reservations");
    }

    // every closed order's movements, line by line
    $reasonsByItem = function (string $ref): array {
        $out = [];
        foreach (DB::table('stock_movements')->where('reservation_ref', $ref)->orderBy('id')->get() as $row) {
            $out[$row->item_id][] = $row->reason;
        }

        return $out;
    };
    foreach (Order::query()->where('status', OrderStatus::Closed->value)->get() as $order) {
        foreach (OrderLine::query()->where('order_id', $order->id)->get() as $line) {
            $expectedSteps = match (true) {
                $line->status === LineStatus::Rejected => ['reservation', 'reservation_release'],
                $line->station_id !== null => ['reservation', 'production_consume'],
                default => ['reservation', 'sale'],
            };
            $perItem = $reasonsByItem($line->reservation_ref);
            expect($perItem)->not->toBe([], "seed {$seed}: closed order #{$order->id} line #{$line->id} has no movements");
            foreach ($perItem as $itemId => $reasons) {
                expect($reasons)->toBe($expectedSteps, "seed {$seed}: closed order #{$order->id} line #{$line->id} item {$itemId}");
            }
        }
    }
})->with(orderLedgerSeeds())->group('gate:p1', 'property', 'module:orders');
