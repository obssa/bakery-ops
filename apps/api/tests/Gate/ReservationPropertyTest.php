<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\Reserved;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Phase 0 gate, criterion 2: random reserve / release / consume sequences never produce available < 0 on any balance
 * row, and an over-reservation returns Shortfall{ingredient, qty_short in base units, station} and writes nothing.
 * The test explodes the recipes itself (two to-order products sharing an ingredient, one with waste and a yield of 2;
 * a to-stock and a bought-in product reserved as themselves) and predicts every outcome from the balances it reads
 * before each operation. 100 seeds, at most 40 operations each; RESERVATION_PROPERTY_SEED=<n> replays one.
 */

uses(RefreshDatabase::class);

/** @return array<string, array{0: int}> */
function reservationPropertySeeds(): array
{
    $one = getenv('RESERVATION_PROPERTY_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 6001 + $i * 104729, range(1, 100));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

/** @return array<string, BigDecimal> "item:location" => available (on_hand - reserved), straight from stock_balances */
function availableNow(int $branchId): array
{
    return DB::table('stock_balances')->where('branch_id', $branchId)->get()
        ->mapWithKeys(fn (object $r): array => [$r->item_id.':'.$r->location_id => BigDecimal::of((string) $r->on_hand)->minus((string) $r->reserved)])
        ->all();
}

it('never lets reserve, release or consume take available below zero, and names every shortfall', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    $b = $branch->id;
    withActor(User::factory()->create(['branch_id' => $b])->id);
    $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $kitchen = stockLocation($branch, LocationType::Kitchen, $station);
    $display = stockLocation($branch, LocationType::DisplayCase);

    $cheese = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $ham = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $b]);
    $sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);
    $toast = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b]);
    $soda = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $b]);

    $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $sandwich->id, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $cheese->id, 'qty' => '30', 'unit_id' => catalogUnit('g')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ham->id, 'qty' => '45.5', 'unit_id' => catalogUnit('g')->id]);
    $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $toast->id, 'yield_qty' => '2', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $cheese->id, 'qty' => '50', 'unit_id' => catalogUnit('g')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $flour->id, 'qty' => '0.1', 'unit_id' => catalogUnit('kg')->id, 'waste_pct' => '10.00']);

    // what one unit of each product takes, per "item:location", in base units (the test's own explosion)
    $perUnit = [
        $sandwich->id => ["{$cheese->id}:{$kitchen->id}" => '30', "{$ham->id}:{$kitchen->id}" => '45.5'],
        $toast->id => ["{$cheese->id}:{$kitchen->id}" => '25', "{$flour->id}:{$kitchen->id}" => '55'],
        $cake->id => ["{$cake->id}:{$display->id}" => '1'],
        $soda->id => ["{$soda->id}:{$display->id}" => '1'],
    ];
    $where = [$sandwich->id => $kitchen, $toast->id => $kitchen, $cake->id => $display, $soda->id => $display];
    $stationOf = [$kitchen->id => $station->id, $display->id => null];

    // opening stock, sometimes none at all (a purchase of zero is not a movement)
    foreach ([[$cheese, $kitchen, 400], [$ham, $kitchen, 400], [$flour, $kitchen, 600], [$cake, $display, 8], [$soda, $display, 12]] as [$item, $location, $max]) {
        $opening = $rng->getInt(0, $max);
        if ($opening > 0) {
            stockIn($item, $location, (string) $opening);
        }
    }

    $reservations = app(Reservations::class);
    $open = [];       // ref => "item:location" => reserved qty (string)
    $closed = [];     // ref => 'released' | 'consumed'

    $ops = $rng->getInt(1, 40);
    for ($op = 1; $op <= $ops; $op++) {
        $at = "seed {$seed}, op {$op}";
        $available = availableNow($b);
        $count = DB::table('stock_movements')->count();
        $kind = $rng->getInt(0, 9);

        if ($kind <= 4 || $open === []) {
            // reserve 1 to 3 lines; predict the outcome from the balances read above
            $lines = [];
            $need = [];
            for ($n = $rng->getInt(1, 3); $n > 0; $n--) {
                $product = array_keys($perUnit)[$rng->getInt(0, 3)];
                $qty = $rng->getInt(1, 4);
                $lines[] = new ReserveLine($product, (string) $qty, $where[$product]->id);
                foreach ($perUnit[$product] as $key => $unit) {
                    $need[$key] = ($need[$key] ?? BigDecimal::zero())->plus(BigDecimal::of($unit)->multipliedBy($qty)->toScale(4, RoundingMode::HalfUp));
                }
            }
            $short = [];
            foreach ($need as $key => $qty) {
                $have = $available[$key] ?? BigDecimal::zero();
                if ($qty->isGreaterThan($have)) {
                    $short[$key] = (string) $qty->minus($have)->toScale(4);
                }
            }

            $ref = (string) Str::uuid();
            $result = $reservations->reserve($lines, $ref);

            if ($short === []) {
                expect($result)->toBeInstanceOf(Reserved::class, "{$at}: enough stock, yet no reservation");
                $open[$ref] = array_map(fn (BigDecimal $q): string => (string) $q->toScale(4), $need);
            } else {
                expect($result)->toBeInstanceOf(Shortfall::class, "{$at}: short of ".implode(', ', array_keys($short)).', yet reserved')
                    ->and(DB::table('stock_movements')->count())->toBe($count, "{$at}: a shortfall wrote rows");
                /** @var Shortfall $result */
                $named = [];
                foreach ($result->shortages as $shortage) {
                    $named[$shortage->itemId.':'.$shortage->locationId] = $shortage->qtyShort->toString();
                    expect($shortage->stationId)->toBe($stationOf[$shortage->locationId], "{$at}: station of the shortfall");
                }
                $byPair = fn (string $a, string $b): int => array_map('intval', explode(':', $a)) <=> array_map('intval', explode(':', $b));
                uksort($named, $byPair);
                uksort($short, $byPair);
                expect($named)->toBe($short, "{$at}: shortfall lines")
                    ->and($result->ingredientItemId.':'.$result->locationId)->toBe(array_key_first($short), "{$at}: the named ingredient")
                    ->and($result->qtyShort->toString())->toBe($short[array_key_first($short)])
                    ->and($result->stationId)->toBe($stationOf[$result->locationId]);
            }
        } elseif ($kind <= 6) {
            // release an open, closed or unknown ref: never more than once
            $pool = [...array_keys($open), ...array_keys($closed), (string) Str::uuid()];
            $ref = $pool[$rng->getInt(0, count($pool) - 1)];
            $rows = $reservations->release($ref);
            if (isset($open[$ref])) {
                expect($rows)->toHaveCount(count($open[$ref]), "{$at}: release writes one row per reserved pair");
                unset($open[$ref]);
                $closed[$ref] = 'released';
            } else {
                expect($rows)->toBe([], "{$at}: a second release is a no-op");
            }
        } else {
            // consume an open ref as reserved, with less, or with an excess that must fit in what is available
            $pool = [...array_keys($open), ...array_keys($closed)];
            $ref = $pool[$rng->getInt(0, count($pool) - 1)];
            if (! isset($open[$ref])) {
                if ($closed[$ref] === 'released') {
                    expect(fn () => $reservations->consume($ref))->toThrow(InventoryError::class);
                } else {
                    expect($reservations->consume($ref))->toBe([], "{$at}: a second consume is a no-op");
                }

                continue;
            }
            $actual = null;
            $fits = true;
            if ($rng->getInt(0, 1) === 0) {
                $actual = [];
                foreach ($open[$ref] as $key => $reserved) {
                    [$itemId, $locationId] = array_map('intval', explode(':', $key));
                    $delta = $rng->getInt(-3, 3);
                    $used = BigDecimal::of($reserved)->plus($delta);
                    $used = $used->isNegative() ? BigDecimal::zero() : $used;
                    $actual[$itemId] = (string) $used->toScale(4);
                    $excess = $used->minus($reserved);
                    if ($excess->isPositive() && $excess->isGreaterThan($available[$key] ?? BigDecimal::zero())) {
                        $fits = false;
                    }
                }
            }
            if ($fits) {
                $reservations->consume($ref, $actual);
                unset($open[$ref]);
                $closed[$ref] = 'consumed';
            } else {
                expect(fn () => $reservations->consume($ref, $actual))->toThrow(InventoryError::class)
                    ->and(DB::table('stock_movements')->count())->toBe($count, "{$at}: a refused consume wrote rows");
            }
        }

        // the property itself: no balance row anywhere ever shows available < 0, reserved < 0 or on_hand < 0
        foreach (DB::table('stock_balances')->where('branch_id', $b)->get() as $row) {
            $onHand = BigDecimal::of((string) $row->on_hand);
            $reserved = BigDecimal::of((string) $row->reserved);
            expect($reserved->isNegative())->toBeFalse("{$at}: reserved < 0 at {$row->item_id}:{$row->location_id}")
                ->and($onHand->isNegative())->toBeFalse("{$at}: on_hand < 0 at {$row->item_id}:{$row->location_id}")
                ->and($onHand->minus($reserved)->isNegative())->toBeFalse("{$at}: available < 0 at {$row->item_id}:{$row->location_id}");
        }

        // and what is still reserved is exactly what the open refs hold
        $held = [];
        foreach ($open as $pairs) {
            foreach ($pairs as $key => $qty) {
                $held[$key] = ($held[$key] ?? BigDecimal::zero())->plus($qty);
            }
        }
        foreach (DB::table('stock_balances')->where('branch_id', $b)->get() as $row) {
            $key = $row->item_id.':'.$row->location_id;
            expect((string) $row->reserved)->toBe((string) ($held[$key] ?? BigDecimal::zero())->toScale(4), "{$at}: reserved at {$key}");
        }
    }

    $oracle = ledgerOracle($b);
    expect($oracle['balances'])->toBe($oracle['movements'], "seed {$seed}: stock_balances != sum(movements)")
        ->and($oracle['view'])->toBe($oracle['movements'], "seed {$seed}: item_stock_view != sum(movements)");
})->with(reservationPropertySeeds())->group('gate:p0', 'property', 'module:inventory');
