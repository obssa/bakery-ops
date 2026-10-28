<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Identity\Models\Device;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\Reserved;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Reversals;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Contract: orders -> inventory (manifest contracts["orders->inventory"]; ADR 0008).
 *
 * Written BEFORE any code exists in App\Modules\Orders (step 1.1): only Inventory classes, the Catalog classes Inventory
 * itself stands on, and factories. Each case is phrased from Orders' side: "when I, Orders, send an order, mark a line
 * ready, reject it, void the order or take the payment, I rely on ...". The way Orders will call Inventory:
 *   - one reservation ref per order line (the line is consumed at ready, released at reject, on its own);
 *   - an order's lines in ONE call (reserveMany at send, releaseMany at void, consumeMany(sale) at payment), so the
 *     balance rows are locked in one ascending pass and two orders can never deadlock (the companion file
 *     InventoryLockOrderConcurrencyTest.php proves it with real parallel processes);
 *   - a station line draws on its station's location, a display-case line on the display case (Locations).
 * Step 4.1b adds the refund's Reversals (Reversals::of, consumptionsOf, reversed: the plan's cases 13 to 15, at the end of the file).
 * It runs in Inventory's CI job (mod-inventory) for the life of the project.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->padAccount = makeUser($this->branch, 'waiter', ['name' => 'Pad 1']);
    $this->waiter = makeUser($this->branch, 'waiter');
    $this->chef = makeUser($this->branch, 'chef');
    withActor($this->admin->id);

    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->barStation = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b]);
    $this->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $this->bar = stockLocation($this->branch, LocationType::Bar, $this->barStation);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase, $this->tillStation);
    $this->drawer = stockLocation($this->branch, LocationType::CashDrawer, $this->tillStation);

    $ingredient = fn (string $name, string $unit): Item => Item::factory()->ingredient($unit)->create(['branch_id' => $b, 'name_en' => $name]);
    $this->chicken = $ingredient('Chicken breast', 'kg');
    $this->lettuce = $ingredient('Lettuce', 'kg');
    $this->onion = $ingredient('Onion', 'kg');
    $this->tomato = $ingredient('Tomato', 'kg');
    $this->butter = $ingredient('Butter', 'kg');
    $this->espresso = $ingredient('Espresso beans', 'kg');
    $this->milk = $ingredient('Milk', 'L');
    $this->bread = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $b, 'name_en' => 'Toast bread']);

    $product = fn (string $name, Fulfilment $fulfilment, ?Station $route = null): Item => tap(
        Item::factory()->product($fulfilment)->create(['branch_id' => $b, 'name_en' => $name]),
        fn (Item $item) => $route === null ? null : ItemStationAssignment::factory()->create(['branch_id' => $b, 'item_id' => $item->id, 'station_id' => $route->id]),
    );
    $this->sandwich = $product('Chicken sandwich', Fulfilment::ToOrder, $this->kitchenStation);
    $this->club = $product('Club sandwich', Fulfilment::ToOrder, $this->kitchenStation);
    $this->latte = $product('Latte', Fulfilment::ToOrder, $this->barStation);
    $this->croissant = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b, 'name_en' => 'Croissant', 'production_station_id' => $this->cakeRoom->id]);
    $this->water = $product('Bottled water', Fulfilment::BoughtIn);

    $recipe = function (Item $product, array $lines) use ($b): void {
        $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $product->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
        foreach ($lines as [$ingredient, $qty, $unit, $extra]) {
            RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id, 'qty' => $qty, 'unit_id' => catalogUnit($unit)->id] + $extra);
        }
    };
    // the chicken sandwich of the demo bakery: 2 slices of toast bread (a product used as an ingredient), 120 g chicken
    // with 5 % waste (126 g leave the shelf), lettuce, onion, butter; tomato only when a modifier adds it
    $recipe($this->sandwich, [
        [$this->bread, '2', 'slice', []], [$this->chicken, '120', 'g', ['waste_pct' => '5.00']], [$this->lettuce, '20', 'g', []],
        [$this->onion, '15', 'g', []], [$this->butter, '10', 'g', []], [$this->tomato, '40', 'g', ['is_optional' => true]],
    ]);
    $recipe($this->club, [[$this->bread, '3', 'slice', []], [$this->chicken, '80', 'g', []], [$this->lettuce, '15', 'g', []], [$this->butter, '10', 'g', []]]);
    $recipe($this->latte, [[$this->espresso, '18', 'g', []], [$this->milk, '220', 'ml', []]]);

    stockIn($this->bread, $this->kitchen, '20');
    stockIn($this->chicken, $this->kitchen, '1000');
    stockIn($this->lettuce, $this->kitchen, '300');
    stockIn($this->onion, $this->kitchen, '200');
    stockIn($this->tomato, $this->kitchen, '100');
    stockIn($this->butter, $this->kitchen, '500');
    stockIn($this->espresso, $this->bar, '200');
    stockIn($this->milk, $this->bar, '1000');
    stockIn($this->croissant, $this->display, '5');
    stockIn($this->water, $this->display, '24');

    $this->reservations = app(Reservations::class);
    $this->line = fn (Item $item, string $qty, ?Location $at = null, array $deltas = []): ReserveLine => new ReserveLine($item->id, $qty, ($at ?? $this->kitchen)->id, $deltas);
    $this->rowsOf = fn (string $ref) => DB::table('stock_movements')->where('reservation_ref', $ref)->orderBy('id')->get();
});

it('reserves the sum when two lines of my order share an ingredient, one row per ingredient, in base units with waste', function () {
    // one ref for both lines
    $ref = (string) Str::uuid();
    $result = $this->reservations->reserve([($this->line)($this->sandwich, '2'), ($this->line)($this->club, '1')], $ref);

    expect($result)->toBeInstanceOf(Reserved::class)
        ->and($result->qtyFor($this->chicken->id, $this->kitchen->id)->toString())->toBe('332.0000') // 2 x 126 + 80
        ->and($result->qtyFor($this->bread->id, $this->kitchen->id)->toString())->toBe('7.0000')
        ->and($result->qtyFor($this->lettuce->id, $this->kitchen->id)->toString())->toBe('55.0000')
        ->and($result->qtyFor($this->butter->id, $this->kitchen->id)->toString())->toBe('30.0000')
        ->and($result->qtyFor($this->onion->id, $this->kitchen->id)->toString())->toBe('30.0000')
        ->and($result->qtyFor($this->tomato->id, $this->kitchen->id)->toString())->toBe('0.0000', 'an optional line is not reserved');
    $rows = ($this->rowsOf)($ref);
    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('reason')->unique()->values()->all())->toBe(['reservation'])
        ->and($rows->pluck('item_id')->unique())->toHaveCount(5);

    // one ref per line, the way Orders sends: each line holds its own share, the balance holds the sum
    $sandwichRef = (string) Str::uuid();
    $clubRef = (string) Str::uuid();
    $byRef = $this->reservations->reserveMany([
        $sandwichRef => [($this->line)($this->sandwich, '2')],
        $clubRef => [($this->line)($this->club, '1')],
    ]);

    expect($byRef)->toBeArray()->toHaveKeys([$sandwichRef, $clubRef])
        ->and(array_keys($byRef))->toBe([$sandwichRef, $clubRef])
        ->and($byRef[$sandwichRef]->qtyFor($this->chicken->id, $this->kitchen->id)->toString())->toBe('252.0000')
        ->and($byRef[$clubRef]->qtyFor($this->chicken->id, $this->kitchen->id)->toString())->toBe('80.0000')
        ->and(($this->rowsOf)($sandwichRef))->toHaveCount(5)
        ->and(($this->rowsOf)($clubRef))->toHaveCount(4)
        ->and(stockRow($this->chicken, $this->kitchen))->on_hand->toBe('1000.0000')->reserved->toBe('664.0000');
})->group('contract:orders->inventory', 'contract');

it('returns a Shortfall naming the ingredient, the quantity short in its base unit and the station, and writes nothing', function () {
    $before = DB::table('stock_movements')->count();

    // 8 sandwiches need 8 x 126 = 1008 g of chicken; the kitchen holds 1000 g
    $short = $this->reservations->reserve([($this->line)($this->sandwich, '8')], (string) Str::uuid());

    expect($short)->toBeInstanceOf(Shortfall::class)
        ->and($short->ingredientItemId)->toBe($this->chicken->id)
        ->and($short->qtyShort)->toBeInstanceOf(Quantity::class)
        ->and($short->qtyShort->toString())->toBe('8.0000')
        ->and($short->stationId)->toBe($this->kitchenStation->id)
        ->and($short->locationId)->toBe($this->kitchen->id)
        ->and($short->shortages)->toHaveCount(1)
        ->and($short->shortages[0]->unit)->toBe('g')
        ->and($short->shortages[0]->needed->toString())->toBe('1008.0000')
        ->and($short->shortages[0]->available->toString())->toBe('1000.0000');
    // the body Orders puts in its 409: decimal strings, ids, the unit
    expect($short->toArray())->toMatchArray([
        'ingredient_item_id' => $this->chicken->id,
        'qty_short' => '8.0000',
        'station_id' => $this->kitchenStation->id,
        'location_id' => $this->kitchen->id,
    ])->and($short->toArray()['shortages'][0])->toHaveKeys(['ingredient_item_id', 'location_id', 'station_id', 'needed', 'available', 'qty_short', 'unit', 'reservation_ref']);

    // one ref per line: lines are covered in the order they were sent, and the shortfall names the line that is short
    foreach ([['sandwich', 'club'], ['club', 'sandwich']] as [$first, $second]) {
        $lines = ['sandwich' => [$this->sandwich, '7'], 'club' => [$this->club, '2']]; // 882 g + 160 g of 1000 g
        $refs = [$first => (string) Str::uuid(), $second => (string) Str::uuid()];
        $result = $this->reservations->reserveMany([
            $refs[$first] => [($this->line)(...$lines[$first])],
            $refs[$second] => [($this->line)(...$lines[$second])],
        ]);

        expect($result)->toBeInstanceOf(Shortfall::class)
            ->and($result->refs())->toBe([$refs[$second]], "{$first} first: the {$second} line is the short one")
            ->and($result->ingredientItemId)->toBe($this->chicken->id)
            ->and($result->qtyShort->toString())->toBe('42.0000')
            ->and($result->shortages[0]->ref)->toBe($refs[$second])
            ->and($result->for($refs[$first]))->toBe([])
            ->and($result->toArray()['shortages'][0]['reservation_ref'])->toBe($refs[$second]);
    }

    expect(DB::table('stock_movements')->count())->toBe($before, 'nothing is reserved for any line when one is short')
        ->and(stockRow($this->chicken, $this->kitchen))->reserved->toBe('0.0000');
})->group('contract:orders->inventory', 'contract');

it('treats release(ref) twice as a no-op, and releaseMany skips settled and unknown refs', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->club, '1')], $ref);

    $released = $this->reservations->release($ref);
    expect($released)->toHaveCount(4)
        ->and(collect($released)->every(fn (Movement $m): bool => $m->reason === MovementReason::ReservationRelease && $m->reservationRef === $ref))->toBeTrue()
        ->and(stockRow($this->chicken, $this->kitchen))->reserved->toBe('0.0000');

    $count = DB::table('stock_movements')->count();
    expect($this->reservations->release($ref))->toBe([])
        ->and(DB::table('stock_movements')->count())->toBe($count)
        ->and(stockRow($this->chicken, $this->kitchen))->on_hand->toBe('1000.0000')->reserved->toBe('0.0000');

    $open = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->latte, '1', $this->bar)], $open);
    $rows = $this->reservations->releaseMany([$ref, (string) Str::uuid(), $open]);
    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('reservationRef')->unique()->values()->all())->toBe([$open])
        ->and($this->reservations->releaseMany([$ref, $open]))->toBe([]);
})->group('contract:orders->inventory', 'contract');

it('consumes at ready exactly one production_consume per ingredient and reduces reserved by the reserved amount', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->sandwich, '2')], $ref);

    $rows = $this->reservations->consume($ref);

    $consumed = DB::table('stock_movements')->where('reservation_ref', $ref)->where('reason', 'production_consume')->get();
    expect($rows)->toHaveCount(5)
        ->and($consumed)->toHaveCount(5)
        ->and($consumed->countBy('item_id')->unique()->values()->all())->toBe([1], 'exactly one row per ingredient')
        ->and($consumed->firstWhere('item_id', $this->chicken->id)?->qty)->toBe('-252.0000')
        ->and(stockRow($this->chicken, $this->kitchen))->on_hand->toBe('748.0000')->reserved->toBe('0.0000')
        ->and(stockRow($this->bread, $this->kitchen))->on_hand->toBe('16.0000')->reserved->toBe('0.0000');

    $count = DB::table('stock_movements')->count();
    expect($this->reservations->consume($ref))->toBe([], 'a replayed ready writes nothing')
        ->and(DB::table('stock_movements')->count())->toBe($count);

    // the chef used less lettuce than the recipe: still one production_consume per ingredient, the rest is released
    $club = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->club, '1')], $club);
    $this->reservations->consume($club, [$this->lettuce->id => '10']);

    $rows = DB::table('stock_movements')->where('reservation_ref', $club)->where('reason', 'production_consume')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->countBy('item_id')->unique()->values()->all())->toBe([1])
        ->and($rows->firstWhere('item_id', $this->lettuce->id)?->qty)->toBe('-10.0000')
        ->and(stockRow($this->lettuce, $this->kitchen))->on_hand->toBe('250.0000')->reserved->toBe('0.0000'); // 300 - 2 x 20 - 10
})->group('contract:orders->inventory', 'contract');

it('locks the balance rows in one deterministic ascending order whatever the order of my lines', function () {
    $lines = [
        fn (): ReserveLine => ($this->line)($this->sandwich, '1'),
        fn (): ReserveLine => ($this->line)($this->club, '1'),
        fn (): ReserveLine => ($this->line)($this->latte, '1', $this->bar),
        fn (): ReserveLine => ($this->line)($this->croissant, '1', $this->display),
        fn (): ReserveLine => ($this->line)($this->water, '1', $this->display),
    ];
    $locked = [];
    DB::listen(function (QueryExecuted $q) use (&$locked): void {
        if (str_contains($q->sql, 'stock_balances') && str_contains(strtolower($q->sql), 'for update')) {
            $locked[] = [(int) $q->bindings[0], (int) $q->bindings[1]];
        }
    });

    $sequences = [];
    mt_srand(20261021);
    foreach (range(1, 50) as $i) {
        $order = range(0, count($lines) - 1);
        shuffle($order);
        $perLine = [];
        foreach ($order as $n) {
            $perLine[(string) Str::uuid()] = [$lines[$n]()];
        }
        $locked = [];
        DB::beginTransaction();
        $result = $i % 2 === 0
            ? $this->reservations->reserveMany($perLine)                                                       // one ref per line
            : $this->reservations->reserve(array_merge(...array_values($perLine)), (string) Str::uuid());   // one ref
        DB::rollBack();

        expect($result)->not->toBeInstanceOf(Shortfall::class);
        // what matters for deadlocks is the order in which each row is first locked
        $first = [];
        foreach ($locked as $pair) {
            $first[$pair[0].':'.$pair[1]] ??= $pair;
        }
        $sequences[] = array_values($first);
    }

    $expected = $sequences[0];
    $sorted = $expected;
    sort($sorted);
    expect($expected)->toBe($sorted, 'rows are first locked in ascending (item_id, location_id) order')
        ->and(count($expected))->toBe(9); // 5 kitchen ingredients incl. the bread, 2 at the bar, 2 display-case products
    foreach ($sequences as $i => $sequence) {
        expect($sequence)->toBe($expected, "shuffle {$i} locked in another order");
    }
})->group('contract:orders->inventory', 'contract');

it('answers Availability::bulk with floor(min(available / qty_per_unit)) for to_order and the own stock for counter products', function () {
    // stock already held for other orders is not offered again
    $this->reservations->reserve([($this->line)($this->club, '1')], (string) Str::uuid());
    $this->reservations->reserve([($this->line)($this->croissant, '2', $this->display)], (string) Str::uuid());
    $noRecipe = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);

    $expectedToOrder = function (Item $product, Location $at): string {
        $recipe = app(Recipes::class)->activeFor($product);
        $units = null;
        foreach ($recipe?->lines ?? [] as $line) {
            if ($line->isOptional) {
                continue;
            }
            $perUnit = $line->qtyWithWaste()->toBigDecimal()->dividedBy($recipe->yield->toBigDecimal(), 8, RoundingMode::HalfUp);
            $available = app(Stock::class)->of($line->ingredientItemId, $at)->available()->toBigDecimal();
            $makes = $available->dividedBy($perUnit, 0, RoundingMode::Down);
            $units = $units === null || $makes->isLessThan($units) ? $makes : $units;
        }

        return (string) BigDecimal::of((string) ($units ?? 0))->toScale(4);
    };

    $kitchen = app(Availability::class)->bulk([$this->sandwich, $this->club, $noRecipe], $this->kitchen);
    expect($kitchen[$this->sandwich->id]->toString())->toBe($expectedToOrder($this->sandwich, $this->kitchen))
        ->and($kitchen[$this->sandwich->id]->toString())->toBe('7.0000') // chicken: (1000 - 80) / 126
        ->and($kitchen[$this->club->id]->toString())->toBe($expectedToOrder($this->club, $this->kitchen))
        ->and($kitchen[$this->club->id]->toString())->toBe('5.0000') // bread: (20 - 3) / 3
        ->and($kitchen[$noRecipe->id]->toString())->toBe('0.0000');

    $bar = app(Availability::class)->bulk([$this->latte], $this->bar);
    expect($bar[$this->latte->id]->toString())->toBe('4.0000'); // milk: 1000 / 220

    $display = app(Availability::class)->bulk([$this->croissant, $this->water], $this->display);
    expect($display[$this->croissant->id]->toString())->toBe('3.0000')
        ->and($display[$this->water->id]->toString())->toBe('24.0000');

    // never below zero: held stock wasted afterwards leaves available negative, availability answers zero
    stockIn($this->croissant, $this->display, '-4', MovementReason::Waste);
    expect(stockRow($this->croissant, $this->display))->on_hand->toBe('1.0000')->reserved->toBe('2.0000')
        ->and(app(Availability::class)->ofProduct($this->croissant, $this->display)->toString())->toBe('0.0000');
})->group('contract:orders->inventory', 'contract');

it('reserves what a line\'s modifiers make of the recipe, with the deltas Catalog hands over in base units', function () {
    $b = $this->branch->id;
    Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->sandwich->id, 'name_en' => 'No onion', 'name_am' => 'ያለ ሽንኩርት',
        'recipe_delta' => [['ingredient_item_id' => $this->onion->id, 'qty' => '-15', 'unit_id' => catalogUnit('g')->id]]]);
    Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->sandwich->id, 'name_en' => 'Extra chicken', 'name_am' => 'ተጨማሪ ዶሮ', 'price_delta' => '60.00',
        'recipe_delta' => [['ingredient_item_id' => $this->chicken->id, 'qty' => '0.05', 'unit_id' => catalogUnit('kg')->id]]]);
    Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->sandwich->id, 'name_en' => 'With tomato', 'name_am' => 'ከቲማቲም ጋር',
        'recipe_delta' => [['ingredient_item_id' => $this->tomato->id, 'qty' => '40', 'unit_id' => catalogUnit('g')->id]]]);
    $modifiers = collect(app(Modifiers::class)->for($this->sandwich))->keyBy(fn ($m) => $m->name->en);

    // the onion is gone
    stockIn($this->onion, $this->kitchen, '-200', MovementReason::Waste);
    $plain = $this->reservations->reserve([($this->line)($this->sandwich, '1')], (string) Str::uuid());
    expect($plain)->toBeInstanceOf(Shortfall::class)
        ->and($plain->ingredientItemId)->toBe($this->onion->id)
        ->and($plain->qtyShort->toString())->toBe('15.0000');

    // "no onion" is not refused for want of onion
    $noOnion = (string) Str::uuid();
    $result = $this->reservations->reserve([ReserveLine::withModifiers($this->sandwich->id, '1', $this->kitchen->id, [$modifiers['No onion']])], $noOnion);
    expect($result)->toBeInstanceOf(Reserved::class)
        ->and($result->qtyFor($this->onion->id, $this->kitchen->id)->toString())->toBe('0.0000')
        ->and(($this->rowsOf)($noOnion)->pluck('item_id')->all())->not->toContain($this->onion->id);

    // two sandwiches with extra chicken and no onion: 2 x (126 + 50) g of chicken; "with tomato" reserves the optional line
    $extra = (string) Str::uuid();
    $result = $this->reservations->reserveMany([
        $extra => [ReserveLine::withModifiers($this->sandwich->id, '2', $this->kitchen->id, [$modifiers['Extra chicken'], $modifiers['No onion']])],
        ($tomato = (string) Str::uuid()) => [ReserveLine::withModifiers($this->sandwich->id, '1', $this->kitchen->id, [$modifiers['With tomato'], $modifiers['No onion']])],
    ]);
    expect($result)->toBeArray()
        ->and($result[$extra]->qtyFor($this->chicken->id, $this->kitchen->id)->toString())->toBe('352.0000')
        ->and($result[$tomato]->qtyFor($this->tomato->id, $this->kitchen->id)->toString())->toBe('40.0000')
        ->and($result[$tomato]->qtyFor($this->onion->id, $this->kitchen->id)->toString())->toBe('0.0000');

    // and at ready exactly that is consumed
    $this->reservations->consume($extra);
    expect(DB::table('stock_movements')->where('reservation_ref', $extra)->where('item_id', $this->chicken->id)->where('reason', 'production_consume')->value('qty'))->toBe('-352.0000');

    // the same deltas given by hand, per unit and in base units
    $line = new ReserveLine($this->sandwich->id, '3', $this->kitchen->id, [$this->onion->id => '-15', $this->chicken->id => '50']);
    expect($line->deltas[$this->onion->id]->toString())->toBe('-15.0000')
        ->and($line->deltas[$this->chicken->id]->toString())->toBe('50.0000');
})->group('contract:orders->inventory', 'contract');

it('sells my display-case lines at payment with consumeMany(refs, sale): one sale per product, carrying its ref', function () {
    $croissant = (string) Str::uuid();
    $water = (string) Str::uuid();
    $this->reservations->reserveMany([
        $croissant => [($this->line)($this->croissant, '2', $this->display)],
        $water => [($this->line)($this->water, '3', $this->display)],
    ]);

    $rows = $this->reservations->consumeMany([$croissant, $water], MovementReason::Sale);

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->every(fn (Movement $m): bool => $m->reason === MovementReason::Sale))->toBeTrue()
        ->and(collect($rows)->mapWithKeys(fn (Movement $m): array => [$m->reservationRef => $m->qty->toString()])->all())
        ->toBe([$croissant => '-2.0000', $water => '-3.0000'])
        ->and(stockRow($this->croissant, $this->display))->on_hand->toBe('3.0000')->reserved->toBe('0.0000')
        ->and(stockRow($this->water, $this->display))->on_hand->toBe('21.0000')->reserved->toBe('0.0000');

    $count = DB::table('stock_movements')->count();
    expect($this->reservations->consumeMany([$croissant, $water], MovementReason::Sale))->toBe([], 'a replayed payment sells nothing twice')
        ->and(DB::table('stock_movements')->count())->toBe($count);
})->group('contract:orders->inventory', 'contract');

it('releases every open line of a voided order with one releaseMany, and leaves lines consumed at ready consumed', function () {
    $sandwich = (string) Str::uuid();
    $club = (string) Str::uuid();
    $croissant = (string) Str::uuid();
    $this->reservations->reserveMany([
        $sandwich => [($this->line)($this->sandwich, '1')],
        $club => [($this->line)($this->club, '1')],
        $croissant => [($this->line)($this->croissant, '1', $this->display)],
    ]);
    $this->reservations->consume($sandwich); // the kitchen already made the sandwich

    $rows = $this->reservations->releaseMany([$sandwich, $club, $croissant]);

    expect(collect($rows)->pluck('reservationRef')->unique()->sort()->values()->all())->toBe(collect([$club, $croissant])->sort()->values()->all())
        ->and(collect($rows)->every(fn (Movement $m): bool => $m->reason === MovementReason::ReservationRelease))->toBeTrue()
        ->and(DB::table('stock_movements')->where('reservation_ref', $sandwich)->where('reason', 'reservation_release')->count())->toBe(0)
        ->and(stockRow($this->chicken, $this->kitchen))->on_hand->toBe('874.0000')->reserved->toBe('0.0000') // the sandwich's 126 g stay used
        ->and(stockRow($this->croissant, $this->display))->on_hand->toBe('5.0000')->reserved->toBe('0.0000')
        ->and($this->reservations->outstanding($club))->toBe([]);
})->group('contract:orders->inventory', 'contract');

it('tells me where a line\'s stock is: its order station\'s location, the display case for counter products', function () {
    $locations = app(Locations::class);

    expect($locations->forProduct($this->sandwich)?->id)->toBe($this->kitchen->id)
        ->and($locations->forProduct($this->latte->id)?->id)->toBe($this->bar->id)
        ->and($locations->forProduct($this->croissant)?->id)->toBe($this->display->id)
        ->and($locations->forProduct($this->water)?->id)->toBe($this->display->id)
        ->and($locations->forStation($this->kitchenStation)?->id)->toBe($this->kitchen->id)
        ->and($locations->forStation($this->tillStation)?->id)->toBe($this->display->id, 'the till sells from the display case, not the cash drawer')
        ->and($locations->displayCase()?->id)->toBe($this->display->id);

    // a station nobody stocks: no location, so Orders refuses the line instead of reserving somewhere else
    $bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->branch->id]);
    $bread = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id]);
    ItemStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $bread->id, 'station_id' => $bakery->id]);
    expect($locations->forStation($bakery))->toBeNull()
        ->and($locations->forProduct($bread))->toBeNull();

    // availability on the pad is asked at exactly that location
    $at = $locations->forProduct($this->latte);
    expect(app(Availability::class)->ofProduct($this->latte, $at ?? 0)->toString())->toBe('4.0000');
})->group('contract:orders->inventory', 'contract');

it('stamps every movement with the pad account, the acting waiter, the station and the device', function () {
    $pad = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Front pad']);
    $kds = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Kitchen screen']);

    withActor($this->padAccount->id, $this->waiter->id, null, $pad->id);
    $ref = (string) Str::uuid();
    $this->reservations->reserveMany([$ref => [($this->line)($this->club, '1')]]);

    $reserved = ($this->rowsOf)($ref);
    expect($reserved)->toHaveCount(4)
        ->and($reserved->pluck('user_id')->unique()->all())->toBe([$this->padAccount->id])
        ->and($reserved->pluck('acting_user_id')->unique()->all())->toBe([$this->waiter->id])
        ->and($reserved->pluck('device_id')->unique()->all())->toBe([$pad->id]);

    // the chef marks it ready on the kitchen screen
    withActor($this->chef->id, null, $this->kitchenStation->id, $kds->id);
    $this->reservations->consume($ref);
    $consumed = DB::table('stock_movements')->where('reservation_ref', $ref)->where('reason', 'production_consume')->get();
    expect($consumed->pluck('user_id')->unique()->all())->toBe([$this->chef->id])
        ->and($consumed->pluck('acting_user_id')->unique()->all())->toBe([null])
        ->and($consumed->pluck('station_id')->unique()->all())->toBe([$this->kitchenStation->id])
        ->and($consumed->pluck('device_id')->unique()->all())->toBe([$kds->id]);
})->group('contract:orders->inventory', 'contract');

/*
 * Phase 4 (step 4.1b, plan section 2; Ramin's answer to R-C, 2026-10-28): a refunded line whose product goes back into stock is put back
 * with Reversals::of(movementIds): one `return` per original sale / production_consume, pointing at it, so the originals are never
 * touched. Phrased from Orders' side: "when I refund a line, I ask Inventory for the movements its reservation consumed of the product,
 * and rely on it to reverse each of them once, in the Ledger's lock order, or to refuse and write nothing".
 */

it('reverses each original with one return of the opposite quantity at its place, pointing at it, with its reversal row, in the Ledger lock order', function () {
    $croissant = (string) Str::uuid();
    $water = (string) Str::uuid();
    $sandwich = (string) Str::uuid();
    $this->reservations->reserveMany([
        $croissant => [($this->line)($this->croissant, '2', $this->display)],
        $water => [($this->line)($this->water, '3', $this->display)],
        $sandwich => [($this->line)($this->sandwich, '1')],
    ]);
    $this->reservations->consume($sandwich); // the kitchen made it: production_consume of its ingredients
    $this->reservations->consumeMany([$croissant, $water], MovementReason::Sale); // paid: one sale per display-case line
    $reversals = app(Reversals::class);
    $consumedOf = fn (string $ref, Item $item): Movement => collect($reversals->consumptionsOf($ref, $item->id))->sole();
    $croissantSale = $consumedOf($croissant, $this->croissant);
    $waterSale = $consumedOf($water, $this->water);
    $chickenUsed = $consumedOf($sandwich, $this->chicken);
    expect($croissantSale->reason)->toBe(MovementReason::Sale)
        ->and($chickenUsed->reason)->toBe(MovementReason::ProductionConsume)
        ->and($reversals->consumptionsOf($croissant, $this->water->id))->toBe([], 'only the consumption of the item asked for');
    $ids = [$croissantSale->id, $waterSale->id, $chickenUsed->id];
    $originals = DB::table('stock_movements')->whereIn('id', $ids)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();
    $locked = [];
    DB::listen(function (QueryExecuted $q) use (&$locked): void {
        if (str_contains($q->sql, 'stock_balances') && str_contains(strtolower($q->sql), 'for update')) {
            $locked[] = [(int) $q->bindings[0], (int) $q->bindings[1]];
        }
    });

    $returns = $reversals->of([$waterSale->id, $chickenUsed->id, $croissantSale->id]);

    expect($returns)->toHaveCount(3)
        ->and(array_map(fn (Movement $m): ?int => $m->refId, $returns))->toBe([$waterSale->id, $chickenUsed->id, $croissantSale->id], 'in the order asked')
        ->and(collect($returns)->every(fn (Movement $m): bool => $m->reason === MovementReason::Returned && $m->refType === 'stock_movement' && $m->reservationRef === null))->toBeTrue()
        ->and(array_map(fn (Movement $m): string => $m->qty->toString(), $returns))->toBe(['3.0000', '126.0000', '2.0000'])
        ->and(array_map(fn (Movement $m): int => $m->locationId, $returns))->toBe([$this->display->id, $this->kitchen->id, $this->display->id])
        ->and(array_map(fn (Movement $m): ?int => $m->toLocationId, $returns))->toBe([$this->display->id, $this->kitchen->id, $this->display->id])
        ->and(stockRow($this->croissant, $this->display))->on_hand->toBe('5.0000')->reserved->toBe('0.0000')
        ->and(stockRow($this->water, $this->display))->on_hand->toBe('24.0000')
        ->and(stockRow($this->chicken, $this->kitchen))->on_hand->toBe('1000.0000');
    foreach ($returns as $return) {
        expect(DB::table('stock_movement_reversals')->where('movement_id', $return->id)->where('reverses_movement_id', $return->refId)->where('branch_id', $this->branch->id)->count())
            ->toBe(1, "reversal row of {$return->id}");
    }
    // the originals are byte-identical; the balance rows were first locked in ascending (item_id, location_id) order
    expect(DB::table('stock_movements')->whereIn('id', $ids)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all())->toBe($originals);
    $first = [];
    foreach ($locked as $pair) {
        $first[$pair[0].':'.$pair[1]] ??= $pair;
    }
    $sorted = array_values($first);
    sort($sorted);
    expect(array_values($first))->toBe($sorted)->and($first)->toHaveCount(3)
        ->and($reversals->reversed([...$ids, $returns[0]->id]))
        ->toEqual([$croissantSale->id => $returns[2]->id, $waterSale->id => $returns[0]->id, $chickenUsed->id => $returns[1]->id]);
})->group('contract:orders->inventory', 'contract');

it('refuses to reverse what is not a sale or a consumption, a movement of another branch or one reversed already, and writes nothing for the batch', function () {
    $croissant = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->croissant, '1', $this->display)], $croissant);
    $sale = $this->reservations->consume($croissant, null, MovementReason::Sale)[0];
    $purchase = stockIn($this->milk, $this->bar, '100');
    $reversals = app(Reversals::class);
    $refusal = function (array $ids) use ($reversals): ?string {
        try {
            $reversals->of($ids);
        } catch (InventoryError $e) {
            return $e->messageKey;
        }

        return null;
    };
    $movements = DB::table('stock_movements')->count();

    // a purchase is not undone by a refund; nothing is written for the good id beside it either
    expect($refusal([$sale->id, $purchase->id]))->toBe('inventory.reversal.not_reversible')
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(DB::table('stock_movement_reversals')->count())->toBe(0);

    // a sale of another branch is not found from here, nor an id that does not exist
    $other = Branch::factory()->create();
    $theirs = DB::table('stock_movements')->insertGetId([
        'branch_id' => $other->id, 'item_id' => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $other->id])->id,
        'from_location_id' => Location::factory()->create(['branch_id' => $other->id])->id, 'qty' => '-1.0000', 'reason' => 'sale',
        'ref_type' => 'probe', 'user_id' => $this->admin->id, 'created_at' => now(),
    ]);
    expect($refusal([$theirs]))->toBe('inventory.reversal.not_found')
        ->and($refusal([987654321]))->toBe('inventory.reversal.not_found');

    // reversed once: a duplicate id in one call reverses it once, and the second call is refused
    expect($reversals->of([$sale->id, $sale->id]))->toHaveCount(1);
    $after = DB::table('stock_movements')->count();
    expect($refusal([$sale->id]))->toBe('inventory.reversal.reversed')
        ->and(DB::table('stock_movements')->count())->toBe($after)
        ->and(DB::table('stock_movement_reversals')->where('reverses_movement_id', $sale->id)->count())->toBe(1)
        ->and(stockRow($this->croissant, $this->display))->on_hand->toBe('5.0000')
        ->and($reversals->of([]))->toBe([]);
})->group('contract:orders->inventory', 'contract');

it('keeps the ledger oracle after reversals, and 50 random sale and reversal sequences never take on_hand or reserved below zero', function () {
    $given = getenv('REVERSALS_PROPERTY_SEED');
    $seed = is_string($given) && ctype_digit($given) ? (int) $given : 20261109;
    fwrite(STDERR, "Reversals property seed {$seed} (REVERSALS_PROPERTY_SEED=<n> replays one)\n");
    $random = new Randomizer(new Mt19937($seed));
    $reversals = app(Reversals::class);
    $products = [[$this->croissant, $this->display], [$this->water, $this->display]];
    $sold = [];
    $check = function (string $step) use ($seed): void {
        $o = ledgerOracle($this->branch->id);
        expect($o['movements'])->toBe($o['balances'], "seed {$seed}, {$step}: movements vs balances")
            ->and($o['balances'])->toBe($o['view'], "seed {$seed}, {$step}: balances vs view");
        foreach (DB::table('stock_balances')->where('branch_id', $this->branch->id)->get() as $row) {
            expect(BigDecimal::of((string) $row->on_hand)->isNegative())->toBeFalse("seed {$seed}, {$step}: on_hand of {$row->item_id}:{$row->location_id}")
                ->and(BigDecimal::of((string) $row->reserved)->isNegative())->toBeFalse("seed {$seed}, {$step}: reserved of {$row->item_id}:{$row->location_id}");
        }
    };

    foreach (range(1, 50) as $step) {
        $open = array_values(array_filter($sold, fn (array $s): bool => ! $s['reversed']));
        if ($open !== [] && $random->getInt(0, 2) === 0) {
            $pick = $open[$random->getInt(0, count($open) - 1)];
            $reversals->of([$pick['id']]);
            $sold[$pick['id']]['reversed'] = true;
            $check("step {$step}: reverse {$pick['id']}");

            continue;
        }
        [$item, $at] = $products[$random->getInt(0, 1)];
        $qty = (string) $random->getInt(1, 3);
        if (BigDecimal::of($qty)->isGreaterThan((string) (stockRow($item, $at)->on_hand ?? '0'))) {
            stockIn($item, $at, '6'); // a delivery keeps the display case stocked
        }
        $ref = (string) Str::uuid();
        $this->reservations->reserve([($this->line)($item, $qty, $at)], $ref);
        $sale = $this->reservations->consume($ref, null, MovementReason::Sale)[0];
        $sold[$sale->id] = ['id' => $sale->id, 'reversed' => false];
        $check("step {$step}: sell {$qty} of {$item->id}");
    }

    $reversedCount = count(array_filter($sold, fn (array $s): bool => $s['reversed']));
    expect($reversedCount)->toBeGreaterThan(0, "seed {$seed}: the sequence reversed something")
        ->and(DB::table('stock_movement_reversals')->where('branch_id', $this->branch->id)->count())->toBe($reversedCount);
})->group('contract:orders->inventory', 'contract', 'property');
