<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Enums\StationCode;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6b (plan section 4, "GET /display-case"): the till's display-case panel. The products held at the branch's display case: on hand,
 * reserved, available, a `low` flag for one to three left, the state (out, low, ok), the station that makes the product and whether
 * the kitchen can make more (a to_stock product with an active recipe). Names in both languages. inventory.view; polled by the till,
 * so ETag and 304 in a fixed number of queries.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->cashier = makeUser($this->branch, 'cashier');
    loginAs($this->cashier);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->case = stockLocation($this->branch, LocationType::DisplayCase);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $this->product = fn (string $en, string $am, Fulfilment $fulfilment, string $stock, array $over = []): Item => tap(
        Item::factory()->product($fulfilment)->create(['branch_id' => $b, 'name_en' => $en, 'name_am' => $am] + $over),
        fn (Item $item) => $stock === '0' ? null : stockIn($item, $this->case, $stock),
    );
    $this->recipeFor = function (Item $product): void {
        $recipe = Recipe::factory()->create(['branch_id' => $this->branch->id, 'product_item_id' => $product->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
        RecipeLine::factory()->create(['branch_id' => $this->branch->id, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '100', 'unit_id' => catalogUnit('g')->id]);
    };
    $this->panel = fn (array $headers = []) => $this->withHeaders($headers)->getJson('/api/v1/display-case');
});

it('lists the products held at the display case with their figures, both names, the producing station and whether they can be made', function () {
    $cake = ($this->product)('Cake slice', 'የኬክ ቁራጭ', Fulfilment::ToStock, '10', ['production_station_id' => $this->cakeRoom->id]);
    ($this->recipeFor)($cake);
    app(Reservations::class)->reserve([new ReserveLine($cake->id, '4', $this->case->id)], (string) Str::uuid());

    $response = ($this->panel)()->assertOk();

    expect(array_keys($response->json()))->toBe(['location_id', 'data'])
        ->and($response->json('location_id'))->toBe($this->case->id)
        ->and($response->json('data'))->toBe([[
            'item' => ['id' => $cake->id, 'name_en' => 'Cake slice', 'name_am' => 'የኬክ ቁራጭ'],
            'unit' => 'pcs', 'on_hand' => '10.0000', 'reserved' => '4.0000', 'available' => '6.0000',
            'low' => false, 'state' => 'ok', 'production_station_id' => $this->cakeRoom->id, 'producible' => true,
        ]]);
});

it('flags one to three left as low, none as out, and more as ok', function () {
    $three = ($this->product)('Three', 'ሦስት', Fulfilment::ToStock, '3');
    $one = ($this->product)('One', 'አንድ', Fulfilment::ToStock, '1');
    $four = ($this->product)('Four', 'አራት', Fulfilment::ToStock, '4');
    $none = ($this->product)('None', 'ምንም', Fulfilment::ToStock, '2');
    stockIn($none, $this->case, '-2', MovementReason::Waste);

    $rows = collect(($this->panel)()->assertOk()->json('data'))->keyBy(fn (array $r): int => $r['item']['id']);

    expect($rows[$three->id])->toMatchArray(['low' => true, 'state' => 'low'])
        ->and($rows[$one->id])->toMatchArray(['low' => true, 'state' => 'low'])
        ->and($rows[$four->id])->toMatchArray(['low' => false, 'state' => 'ok'])
        ->and($rows[$none->id])->toMatchArray(['low' => false, 'state' => 'out', 'available' => '0.0000']);
});

it('puts out first, then low, then the rest by name', function () {
    ($this->product)('Zebra cake', 'ሀ', Fulfilment::ToStock, '9');
    ($this->product)('Apple pie', 'ለ', Fulfilment::ToStock, '9');
    ($this->product)('Low one', 'ሐ', Fulfilment::ToStock, '2');
    $out = ($this->product)('Out one', 'መ', Fulfilment::ToStock, '2');
    stockIn($out, $this->case, '-2', MovementReason::Waste);

    expect(array_column(array_column(($this->panel)()->assertOk()->json('data'), 'item'), 'name_en'))->toBe(['Out one', 'Low one', 'Apple pie', 'Zebra cake']);
});

it('is producible only for a to_stock product with an active recipe, and has a producing station only when something makes it', function () {
    $made = ($this->product)('Made', 'ተሰራ', Fulfilment::ToStock, '5', ['production_station_id' => $this->cakeRoom->id]);
    ($this->recipeFor)($made);
    $bare = ($this->product)('No recipe', 'የለም', Fulfilment::ToStock, '5');
    $bought = ($this->product)('Water', 'ውሃ', Fulfilment::BoughtIn, '5');

    $rows = collect(($this->panel)()->assertOk()->json('data'))->keyBy(fn (array $r): int => $r['item']['id']);

    expect($rows[$made->id])->toMatchArray(['producible' => true, 'production_station_id' => $this->cakeRoom->id])
        ->and($rows[$bare->id])->toMatchArray(['producible' => false, 'production_station_id' => null])
        ->and($rows[$bought->id])->toMatchArray(['producible' => false, 'production_station_id' => null]);
});

it('leaves out ingredients and the stock of other places', function () {
    $store = stockLocation($this->branch, LocationType::Store);
    $cake = ($this->product)('Cake', 'ኬክ', Fulfilment::ToStock, '5');
    stockIn($this->flour, $this->case, '900');
    $elsewhere = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    stockIn($elsewhere, $store, '9');

    expect(array_column(array_column(($this->panel)()->assertOk()->json('data'), 'item'), 'id'))->toBe([$cake->id]);
});

it('answers an empty panel when the branch has no display case', function () {
    $this->case->delete();

    ($this->panel)()->assertOk()->assertExactJson(['location_id' => null, 'data' => []]);
});

it('never shows another branch\'s display case', function () {
    $foreign = stockLocation($this->other, LocationType::DisplayCase);
    $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->other->id]);
    DB::table('stock_balances')->insert(['branch_id' => $this->other->id, 'item_id' => $item->id, 'location_id' => $foreign->id, 'on_hand' => '5', 'reserved' => '0', 'updated_at' => now()]);

    ($this->panel)()->assertOk()->assertJsonPath('location_id', $this->case->id)->assertJsonPath('data', []);
});

it('is for the floor: the cashier reads it, the waiter is refused with 403', function () {
    ($this->panel)()->assertOk();
    loginAs(makeUser($this->branch, 'waiter'));

    ($this->panel)()->assertForbidden();
});

it('stamps an ETag, answers 304 when nothing changed, and a new ETag after a movement or a catalog change', function () {
    $cake = ($this->product)('Cake', 'ኬክ', Fulfilment::ToStock, '9');

    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $first = ($this->panel)()->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = (string) $first->headers->get('ETag');
    $again = ($this->panel)(['If-None-Match' => $etag]);
    $again->assertStatus(304);
    expect($again->getContent())->toBe('')->and($again->headers->get('ETag'))->toBe($etag);

    stockIn($cake, $this->case, '-1', MovementReason::Waste);
    $moved = ($this->panel)(['If-None-Match' => $etag])->assertOk();
    $afterMove = (string) $moved->headers->get('ETag');
    expect($afterMove)->not->toBe($etag)->and($moved->json('data.0.available'))->toBe('8.0000');

    DB::table('branches')->where('id', $this->branch->id)->increment('catalog_version');
    expect((string) ($this->panel)(['If-None-Match' => $afterMove])->assertOk()->headers->get('ETag'))->not->toBe($afterMove);
});

it('does the work in a fixed number of queries as the products grow, and fewer for a 304', function () {
    $count = function (array $headers = []): int {
        $n = 0;
        DB::listen(function (QueryExecuted $q) use (&$n): void {
            $n++;
        });
        ($this->panel)($headers);

        return $n;
    };
    $make = function (int $howMany, int $from): void {
        for ($i = $from; $i < $from + $howMany; $i++) {
            $product = ($this->product)('P'.$i, 'ፒ'.$i, $i % 3 === 0 ? Fulfilment::BoughtIn : Fulfilment::ToStock, (string) ($i % 5 + 1), $i % 2 === 0 ? ['production_station_id' => $this->cakeRoom->id] : []);
            if ($i % 3 !== 0) {
                ($this->recipeFor)($product);
            }
        }
    };
    $make(2, 1);
    $count(); // the first request of a test also warms the permission and role caches
    $few = $count();
    $make(12, 3);
    $many = $count();
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $etag = (string) ($this->panel)()->headers->get('ETag');

    expect($many)->toBe($few)->and($count(['If-None-Match' => $etag]))->toBeLessThan($many);
});
