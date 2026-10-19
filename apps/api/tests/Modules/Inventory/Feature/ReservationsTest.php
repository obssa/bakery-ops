<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\Reserved;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Reservations (manifest inventory.provides; traps.md, Reserved accounting): reserve(lines, ref) explodes each line
 * through Catalog's Recipes::activeFor (a to-order product into its ingredients, in base units, waste included), sums
 * the ingredients lines share, locks the balance rows and either reserves everything or nothing; it never leaves
 * available below zero and names what is short (ingredient, qty_short in base units, station). release(ref) is
 * idempotent; consume(ref, actual) turns the reservation into production_consume (or sale) exactly once.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $b = $this->branch->id;
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase);

    $this->cheese = Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => 'Cheese']);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Flour']);
    $this->tomato = Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => 'Tomato']);
    $this->bread = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b, 'name_en' => 'Bread slice']);
    $this->sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => 'Sandwich']);
    $this->toast = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => 'Toast']);
    $this->latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => 'Latte (no recipe yet)']);
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b, 'name_en' => 'Cake']);
    $this->water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $b, 'name_en' => 'Water']);

    // a sandwich: 2 bread slices (a product used as an ingredient) + 30 g cheese per piece; tomato is optional
    $sandwich = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->sandwich->id, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $sandwich->id, 'ingredient_item_id' => $this->bread->id, 'qty' => '2', 'unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $sandwich->id, 'ingredient_item_id' => $this->cheese->id, 'qty' => '30', 'unit_id' => catalogUnit('g')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $sandwich->id, 'ingredient_item_id' => $this->tomato->id, 'qty' => '20', 'unit_id' => catalogUnit('g')->id, 'is_optional' => true]);
    // toast: yields 2 pieces from 50 g cheese and 0.1 kg flour with 10 % waste (110 g leaves the shelf)
    $toast = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->toast->id, 'yield_qty' => '2', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $toast->id, 'ingredient_item_id' => $this->cheese->id, 'qty' => '50', 'unit_id' => catalogUnit('g')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $toast->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '0.1', 'unit_id' => catalogUnit('kg')->id, 'waste_pct' => '10.00']);

    stockIn($this->bread, $this->kitchen, '10');
    stockIn($this->cheese, $this->kitchen, '200');
    stockIn($this->flour, $this->kitchen, '1000');
    stockIn($this->cake, $this->display, '3');
    stockIn($this->water, $this->display, '24');

    $this->reservations = app(Reservations::class);
    $this->line = fn (Item $item, string $qty, $location = null): ReserveLine => new ReserveLine($item->id, $qty, ($location ?? $this->kitchen)->id);
});

it('reserves the sum when two lines share an ingredient, in base units with waste, one row per ingredient', function () {
    $ref = (string) Str::uuid();

    $result = $this->reservations->reserve([($this->line)($this->sandwich, '2'), ($this->line)($this->toast, '1')], $ref);

    expect($result)->toBeInstanceOf(Reserved::class)
        ->and($result->ref)->toBe($ref)
        ->and($result->qtyFor($this->cheese->id, $this->kitchen->id)->toString())->toBe('85.0000') // 2 x 30 + 50 / 2
        ->and($result->qtyFor($this->bread->id, $this->kitchen->id)->toString())->toBe('4.0000')
        ->and($result->qtyFor($this->flour->id, $this->kitchen->id)->toString())->toBe('55.0000') // 110 g per 2 pieces
        ->and($result->lines)->toHaveCount(3);

    expect(stockRow($this->cheese, $this->kitchen))->on_hand->toBe('200.0000')->reserved->toBe('85.0000')
        ->and(stockRow($this->bread, $this->kitchen)?->reserved)->toBe('4.0000')
        ->and(stockRow($this->flour, $this->kitchen)?->reserved)->toBe('55.0000')
        ->and(stockRow($this->tomato, $this->kitchen))->toBeNull('an optional line is not reserved');

    $rows = DB::table('stock_movements')->where('reservation_ref', $ref)->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('reason')->unique()->values()->all())->toBe(['reservation'])
        ->and($rows->firstWhere('item_id', $this->cheese->id)?->qty)->toBe('85.0000');
})->group('module:inventory', 'feature');

it('returns a Shortfall naming the ingredient, the quantity short in base units and the station, and reserves nothing', function () {
    $ref = (string) Str::uuid();
    $before = DB::table('stock_movements')->count();

    // 6 sandwiches + 2 toasts: cheese 180 + 50 = 230 g of 200, bread 12 of 10
    $result = $this->reservations->reserve([($this->line)($this->sandwich, '6'), ($this->line)($this->toast, '2')], $ref);

    expect($result)->toBeInstanceOf(Shortfall::class);
    $short = collect($result->shortages)->keyBy('itemId');
    expect($short->keys()->all())->toEqualCanonicalizing([$this->cheese->id, $this->bread->id])
        ->and($short[$this->cheese->id]->qtyShort->toString())->toBe('30.0000')
        ->and($short[$this->cheese->id]->needed->toString())->toBe('230.0000')
        ->and($short[$this->cheese->id]->available->toString())->toBe('200.0000')
        ->and($short[$this->cheese->id]->unit)->toBe('g')
        ->and($short[$this->bread->id]->qtyShort->toString())->toBe('2.0000')
        ->and($short[$this->bread->id]->unit)->toBe('pcs')
        ->and($short[$this->bread->id]->stationId)->toBe($this->kitchenStation->id);

    $first = min($this->cheese->id, $this->bread->id);
    expect($result->ingredientItemId)->toBe($first)
        ->and($result->qtyShort->toString())->toBe($short[$first]->qtyShort->toString())
        ->and($result->stationId)->toBe($this->kitchenStation->id)
        ->and($result->locationId)->toBe($this->kitchen->id)
        ->and($result->toArray()['ingredient_item_id'])->toBe($first);

    expect(DB::table('stock_movements')->count())->toBe($before)
        ->and(stockRow($this->cheese, $this->kitchen)?->reserved)->toBe('0.0000')
        ->and(stockRow($this->bread, $this->kitchen)?->reserved)->toBe('0.0000');
})->group('module:inventory', 'feature');

it('reserves a to-stock or bought-in product itself at the location, and never takes available below zero', function () {
    $first = $this->reservations->reserve([($this->line)($this->cake, '2', $this->display), ($this->line)($this->water, '24', $this->display)], (string) Str::uuid());
    expect($first)->toBeInstanceOf(Reserved::class)
        ->and(stockRow($this->cake, $this->display)?->reserved)->toBe('2.0000')
        ->and(stockRow($this->water, $this->display)?->reserved)->toBe('24.0000');

    // the last cake goes to the next order; the one after that is short by one, at a display case with no station
    expect($this->reservations->reserve([($this->line)($this->cake, '1', $this->display)], (string) Str::uuid()))->toBeInstanceOf(Reserved::class);
    $short = $this->reservations->reserve([($this->line)($this->cake, '1', $this->display)], (string) Str::uuid());
    expect($short)->toBeInstanceOf(Shortfall::class)
        ->and($short->ingredientItemId)->toBe($this->cake->id)
        ->and($short->qtyShort->toString())->toBe('1.0000')
        ->and($short->stationId)->toBeNull();

    expect(stockRow($this->cake, $this->display))->on_hand->toBe('3.0000')->reserved->toBe('3.0000');
})->group('module:inventory', 'feature');

it('treats the ref as the key of the reservation: reserving it again returns the same reservation, no new rows', function () {
    $ref = (string) Str::uuid();
    $first = $this->reservations->reserve([($this->line)($this->sandwich, '1')], $ref);
    $count = DB::table('stock_movements')->count();

    $again = $this->reservations->reserve([($this->line)($this->sandwich, '1')], $ref);

    expect($again)->toBeInstanceOf(Reserved::class)
        ->and($again->ref)->toBe($ref)
        ->and($again->qtyFor($this->cheese->id, $this->kitchen->id)->toString())->toBe($first->qtyFor($this->cheese->id, $this->kitchen->id)->toString())
        ->and(DB::table('stock_movements')->count())->toBe($count)
        ->and(stockRow($this->cheese, $this->kitchen)?->reserved)->toBe('30.0000');
})->group('module:inventory', 'feature');

it('releases a reservation back to available; releasing it twice, or an unknown ref, is a no-op', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->sandwich, '2')], $ref);

    $released = $this->reservations->release($ref);
    expect($released)->toHaveCount(2)
        ->and(collect($released)->every(fn ($m): bool => $m->reason === MovementReason::ReservationRelease && $m->reservationRef === $ref))->toBeTrue()
        ->and(stockRow($this->cheese, $this->kitchen))->on_hand->toBe('200.0000')->reserved->toBe('0.0000')
        ->and($this->reservations->outstanding($ref))->toBe([]);

    $count = DB::table('stock_movements')->count();
    expect($this->reservations->release($ref))->toBe([])
        ->and($this->reservations->release((string) Str::uuid()))->toBe([])
        ->and(DB::table('stock_movements')->count())->toBe($count);
})->group('module:inventory', 'feature');

it('consumes a reservation exactly once: one production_consume per ingredient, reserved and on_hand down by the reserved amount', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->sandwich, '2')], $ref);

    $consumed = $this->reservations->consume($ref);

    expect($consumed)->toHaveCount(2);
    $rows = DB::table('stock_movements')->where('reservation_ref', $ref)->where('reason', 'production_consume')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('item_id', $this->cheese->id)?->qty)->toBe('-60.0000')
        ->and($rows->firstWhere('item_id', $this->bread->id)?->qty)->toBe('-4.0000');
    expect(stockRow($this->cheese, $this->kitchen))->on_hand->toBe('140.0000')->reserved->toBe('0.0000')
        ->and(stockRow($this->bread, $this->kitchen))->on_hand->toBe('6.0000')->reserved->toBe('0.0000');

    $count = DB::table('stock_movements')->count();
    expect($this->reservations->consume($ref))->toBe([])
        ->and($this->reservations->release($ref))->toBe([])
        ->and(DB::table('stock_movements')->count())->toBe($count);
})->group('module:inventory', 'feature');

it('refuses to consume a released reservation and an unknown one', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->sandwich, '1')], $ref);
    $this->reservations->release($ref);

    try {
        $this->reservations->consume($ref);
        $this->fail('a released reservation cannot be consumed');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.reservation.released')->and($e->status)->toBe(409);
    }

    try {
        $this->reservations->consume((string) Str::uuid());
        $this->fail('an unknown reservation cannot be consumed');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.reservation.not_found')->and($e->status)->toBe(404);
    }

    try {
        $this->reservations->reserve([($this->line)($this->sandwich, '1')], $ref);
        $this->fail('a released reservation cannot be reserved again under the same ref');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.reservation.closed')->and($e->status)->toBe(409);
    }
})->group('module:inventory', 'feature');

it('consumes what was actually used: less releases the rest, more takes the excess from available', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->toast, '2')], $ref); // cheese 50 g, flour 110 g

    $this->reservations->consume($ref, [$this->cheese->id => '40', $this->flour->id => '130']);

    $rows = DB::table('stock_movements')->where('reservation_ref', $ref)->orderBy('id')->get();
    $byReason = fn (int $item, string $reason) => $rows->where('item_id', $item)->where('reason', $reason)->pluck('qty')->all();
    expect($byReason($this->cheese->id, 'production_consume'))->toBe(['-40.0000'])
        ->and($byReason($this->cheese->id, 'reservation_release'))->toBe(['-10.0000'])
        ->and($byReason($this->flour->id, 'production_consume'))->toBe(['-110.0000']);

    $excess = DB::table('stock_movements')->where('item_id', $this->flour->id)->where('reason', 'production_consume')->whereNull('reservation_ref')->pluck('qty')->all();
    expect($excess)->toBe(['-20.0000'])
        ->and(stockRow($this->cheese, $this->kitchen))->on_hand->toBe('160.0000')->reserved->toBe('0.0000')
        ->and(stockRow($this->flour, $this->kitchen))->on_hand->toBe('870.0000')->reserved->toBe('0.0000');
})->group('module:inventory', 'feature');

it('refuses an excess that would eat into another reservation, and writes nothing', function () {
    $mine = (string) Str::uuid();
    $theirs = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->toast, '2')], $mine);       // cheese 50
    $this->reservations->reserve([($this->line)($this->sandwich, '5')], $theirs);  // cheese 150: all 200 g held
    $count = DB::table('stock_movements')->count();

    try {
        $this->reservations->consume($mine, [$this->cheese->id => '60']);
        $this->fail('the excess must fit in what is available');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.stock.insufficient');
    }

    expect(DB::table('stock_movements')->count())->toBe($count)
        ->and(stockRow($this->cheese, $this->kitchen))->on_hand->toBe('200.0000')->reserved->toBe('200.0000');
})->group('module:inventory', 'feature');

it('consumes a display-case reservation as a sale', function () {
    $ref = (string) Str::uuid();
    $this->reservations->reserve([($this->line)($this->cake, '1', $this->display)], $ref);

    $rows = $this->reservations->consume($ref, null, MovementReason::Sale);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->reason)->toBe(MovementReason::Sale)
        ->and($rows[0]->qty->toString())->toBe('-1.0000')
        ->and(stockRow($this->cake, $this->display))->on_hand->toBe('2.0000')->reserved->toBe('0.0000');

    expect(fn () => $this->reservations->consume((string) Str::uuid(), null, MovementReason::Waste))->toThrow(InvalidArgumentException::class);
})->group('module:inventory', 'feature');

it('refuses a to-order product without an active recipe, and malformed lines', function () {
    try {
        $this->reservations->reserve([($this->line)($this->latte, '1')], (string) Str::uuid());
        $this->fail('a to-order product needs a recipe');
    } catch (InventoryError $e) {
        expect($e->messageKey)->toBe('inventory.recipe.missing')->and($e->status)->toBe(422)->and($e->extra['item_id'] ?? null)->toBe($this->latte->id);
    }

    expect(fn () => $this->reservations->reserve([], (string) Str::uuid()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->reservations->reserve([($this->line)($this->cake, '1', $this->display)], 'not-a-uuid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReserveLine($this->cake->id, '0', $this->display->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReserveLine($this->cake->id, '-1', $this->display->id))->toThrow(InvalidArgumentException::class);
})->group('module:inventory', 'feature');
