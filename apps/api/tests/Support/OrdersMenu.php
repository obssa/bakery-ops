<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The small menu the Orders tests sell from (step 1.3b onward), built the way the admin builds it: stations, the
 * stock locations they work from (the till sells from the display case), ingredients and products with recipes,
 * prices through PriceHistory, routing through ItemStations, modifiers with price and recipe deltas, and stock
 * through the Ledger. Needs a resolved branch and an authenticated ActorContext (actInBranch + withActor).
 *
 *   Chicken sandwich  to_order, kitchen, 250.00: 2 slices toast bread, 120 g chicken (+5 % waste = 126 g), 20 g
 *                     lettuce, 15 g onion, 10 g butter, 40 g tomato optional; modifiers No onion (-15 g onion),
 *                     Extra chicken (+0.05 kg chicken, +60.00), With tomato (+40 g tomato, +10.00)
 *   Latte             to_order, bar, 120.00: 18 g espresso, 220 ml milk; modifier Large (+100 ml milk, +20.00)
 *   Croissant         to_stock, display case, 60.00 (5 in stock)
 *   Black forest cake to_stock (slice), display case, 150.00 (the last one: 1 in stock)
 *   Bottled water     bought_in, display case, 30.00 (24 in stock)
 * Kitchen stock: 20 slices bread, 1000 g chicken, 300 g lettuce, 200 g onion, 100 g tomato, 500 g butter; bar stock:
 * 200 g espresso, 1000 ml milk.
 */
final class OrdersMenu
{
    public Station $kitchenStation;

    public Station $barStation;

    public Station $tillStation;

    public Location $kitchen;

    public Location $bar;

    public Location $display;

    public Location $drawer;

    public Item $bread;

    public Item $chicken;

    public Item $lettuce;

    public Item $onion;

    public Item $tomato;

    public Item $butter;

    public Item $espresso;

    public Item $milk;

    public Item $sandwich;

    public Item $latte;

    public Item $croissant;

    public Item $cake;

    public Item $water;

    public Modifier $noOnion;

    public Modifier $extraChicken;

    public Modifier $withTomato;

    public Modifier $large;

    private function __construct(public Branch $branch) {}

    public static function build(Branch $branch, ?CarbonImmutable $pricesFrom = null): self
    {
        $menu = new self($branch);
        $b = $branch->id;
        $pricesFrom ??= CarbonImmutable::now()->subDay()->startOfSecond();

        $menu->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
        $menu->barStation = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b]);
        $menu->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
        $menu->kitchen = Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $b, 'station_id' => $menu->kitchenStation->id]);
        $menu->bar = Location::factory()->ofType(LocationType::Bar)->create(['branch_id' => $b, 'station_id' => $menu->barStation->id]);
        $menu->display = Location::factory()->ofType(LocationType::DisplayCase)->create(['branch_id' => $b, 'station_id' => $menu->tillStation->id]);
        $menu->drawer = Location::factory()->ofType(LocationType::CashDrawer)->create(['branch_id' => $b, 'station_id' => $menu->tillStation->id]);

        $ingredient = fn (string $en, string $am, string $unit): Item => Item::factory()->ingredient($unit)->create(['branch_id' => $b, 'name_en' => $en, 'name_am' => $am]);
        $menu->chicken = $ingredient('Chicken breast', 'የዶሮ ፍርምባ', 'kg');
        $menu->lettuce = $ingredient('Lettuce', 'ሰላጣ', 'kg');
        $menu->onion = $ingredient('Onion', 'ሽንኩርት', 'kg');
        $menu->tomato = $ingredient('Tomato', 'ቲማቲም', 'kg');
        $menu->butter = $ingredient('Butter', 'ቅቤ', 'kg');
        $menu->espresso = $ingredient('Espresso beans', 'የኤስፕሬሶ ቡና', 'kg');
        $menu->milk = $ingredient('Milk', 'ወተት', 'L');
        $menu->bread = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $b, 'name_en' => 'Toast bread', 'name_am' => 'የቶስት ዳቦ']);

        $product = fn (string $en, string $am, Fulfilment $fulfilment, string $unit = 'pcs'): Item => Item::factory()->product($fulfilment, $unit)->create(['branch_id' => $b, 'name_en' => $en, 'name_am' => $am]);
        $menu->sandwich = $product('Chicken sandwich', 'የዶሮ ሳንድዊች', Fulfilment::ToOrder);
        $menu->latte = $product('Latte', 'ማኪያቶ', Fulfilment::ToOrder);
        $menu->croissant = $product('Croissant', 'ክሮሳን', Fulfilment::ToStock);
        $menu->cake = $product('Black forest cake', 'ብላክ ፎረስት ኬክ', Fulfilment::ToStock, 'slice');
        $menu->water = $product('Bottled water', 'የታሸገ ውሃ', Fulfilment::BoughtIn);

        $recipe = function (Item $product, array $lines) use ($b): void {
            $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $product->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
            foreach ($lines as [$ingredient, $qty, $unit, $extra]) {
                RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $ingredient->id, 'qty' => $qty, 'unit_id' => catalogUnit($unit)->id] + $extra);
            }
        };
        $recipe($menu->sandwich, [
            [$menu->bread, '2', 'slice', []], [$menu->chicken, '120', 'g', ['waste_pct' => '5.00']], [$menu->lettuce, '20', 'g', []],
            [$menu->onion, '15', 'g', []], [$menu->butter, '10', 'g', []], [$menu->tomato, '40', 'g', ['is_optional' => true]],
        ]);
        $recipe($menu->latte, [[$menu->espresso, '18', 'g', []], [$menu->milk, '220', 'ml', []]]);

        $stations = app(ItemStations::class);
        $stations->replace($menu->sandwich, [$menu->kitchenStation->id]);
        $stations->replace($menu->latte, [$menu->barStation->id]);

        $modifier = fn (Item $item, string $en, string $am, string $price, array $delta): Modifier => Modifier::factory()->create([
            'branch_id' => $b, 'item_id' => $item->id, 'name_en' => $en, 'name_am' => $am, 'price_delta' => $price, 'recipe_delta' => $delta,
        ]);
        $menu->noOnion = $modifier($menu->sandwich, 'No onion', 'ያለ ሽንኩርት', '0.00', [['ingredient_item_id' => $menu->onion->id, 'qty' => '-15', 'unit_id' => catalogUnit('g')->id]]);
        $menu->extraChicken = $modifier($menu->sandwich, 'Extra chicken', 'ተጨማሪ ዶሮ', '60.00', [['ingredient_item_id' => $menu->chicken->id, 'qty' => '0.05', 'unit_id' => catalogUnit('kg')->id]]);
        $menu->withTomato = $modifier($menu->sandwich, 'With tomato', 'ከቲማቲም ጋር', '10.00', [['ingredient_item_id' => $menu->tomato->id, 'qty' => '40', 'unit_id' => catalogUnit('g')->id]]);
        $menu->large = $modifier($menu->latte, 'Large', 'ትልቅ', '20.00', [['ingredient_item_id' => $menu->milk->id, 'qty' => '100', 'unit_id' => catalogUnit('ml')->id]]);

        $prices = app(PriceHistory::class);
        foreach ([[$menu->sandwich, '250.00'], [$menu->latte, '120.00'], [$menu->croissant, '60.00'], [$menu->cake, '150.00'], [$menu->water, '30.00']] as [$item, $price]) {
            $prices->open($item, Money::of($price), $pricesFrom);
        }

        foreach ([[$menu->bread, '20'], [$menu->chicken, '1000'], [$menu->lettuce, '300'], [$menu->onion, '200'], [$menu->tomato, '100'], [$menu->butter, '500']] as [$item, $qty]) {
            stockIn($item, $menu->kitchen, $qty);
        }
        stockIn($menu->espresso, $menu->bar, '200');
        stockIn($menu->milk, $menu->bar, '1000');
        stockIn($menu->croissant, $menu->display, '5');
        stockIn($menu->cake, $menu->display, '1');
        stockIn($menu->water, $menu->display, '24');

        return $menu;
    }

    /**
     * One line as the pad sends it: a fresh client UUID, the product, a decimal-string quantity, modifiers by id.
     *
     * @param  list<Modifier>  $modifiers
     * @return array<string, mixed>
     */
    public function line(Item $item, string $qty = '1', array $modifiers = [], ?string $notes = null): array
    {
        $line = ['client_uuid' => (string) Str::uuid(), 'item_id' => $item->id, 'qty' => $qty];
        if ($modifiers !== []) {
            $line['modifier_ids'] = array_map(static fn (Modifier $m): int => $m->id, $modifiers);
        }
        if ($notes !== null) {
            $line['notes'] = $notes;
        }

        return $line;
    }

    /**
     * The body of POST /orders: a takeaway by default, sent at once unless $extra says otherwise.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function order(array $lines, array $extra = []): array
    {
        return $extra + ['client_uuid' => (string) Str::uuid(), 'type' => 'takeaway', 'lines' => $lines];
    }
}
