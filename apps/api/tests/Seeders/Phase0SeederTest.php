<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\RecipeStep;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoCounts;
use Database\Seeders\Demo\DemoCredentials;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Phase0Seeder;
use Illuminate\Support\Facades\DB;

/*
 * Step 0.8 (phase-0-foundations.md; testing-strategy.md, Seeds): Phase0Seeder builds the demo bakery that every later
 * phase seeder extends and every demo runs on: one branch of its own in Addis Ababa time and birr, the six stations,
 * seven locations, the categories, about 40 ingredients and 25 products with prices, routing and recipes, opening
 * stock written through the Ledger, and one person per role. It is idempotent (a second run writes nothing, not even
 * an audit row), never touches another branch, and takes its credentials from BAKERY_DEMO_PASSWORD (a documented
 * default only in local and testing).
 */

/** Run Phase0Seeder the way db:seed does and answer the demo branch; CurrentBranch is left resolved to it. */
function seedDemoBakery(): Branch
{
    app(Phase0Seeder::class)->run();
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    actInBranch($branch);

    return $branch;
}

/** @return array<string, Item> the demo branch's items by English name */
function demoItemsByName(): array
{
    return Item::query()->get()->keyBy('name_en')->all();
}

/** Where a product is sold from: the location of the station its lines route to, else the display case. */
function demoSaleLocation(Item $product): Location
{
    $station = app(Routing::class)->stationFor($product);

    return $station === null
        ? Location::query()->where('type', LocationType::DisplayCase)->sole()
        : Location::query()->where('station_id', $station->id)->where('type', $station->code->value)->sole();
}

/**
 * Every row of $branchId in each tenant table, as plain arrays: what "untouched" is compared against.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function rowsOfBranch(int $branchId): array
{
    $tables = ['stations', 'locations', 'categories', 'items', 'item_prices', 'item_station_assignments', 'recipes',
        'recipe_lines', 'recipe_steps', 'users', 'user_station_assignments', 'stock_movements', 'stock_balances', 'audit_log'];
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = DB::table($table)->where('branch_id', $branchId)->orderBy('id')->get()
            ->map(fn (object $r): array => (array) $r)->all();
    }

    return $rows;
}

it('builds one branch of its own in Addis Ababa time and birr, with the six stations and seven linked locations', function () {
    $branch = seedDemoBakery();

    expect(Branch::query()->count())->toBe(1)
        ->and($branch->timezone)->toBe('Africa/Addis_Ababa')
        ->and($branch->currency)->toBe('ETB')
        ->and($branch->settings['demo'] ?? null)->toBeTrue();

    $stations = Station::query()->orderBy('sort')->get();
    expect($stations->map(fn (Station $s): string => $s->code->value)->sort()->values()->all())
        ->toBe(collect(StationCode::values())->sort()->values()->all());
    foreach ($stations as $station) {
        $default = $station->code->defaultName();
        expect($station->name_en)->toBe($default->en)->and($station->name_am)->toBe($default->am);
    }

    $locations = Location::query()->with('station')->get();
    $linked = $locations->mapWithKeys(fn (Location $l): array => [$l->type->value => $l->station?->code->value])->all();
    ksort($linked);
    expect($locations)->toHaveCount(7)
        ->and($linked)->toBe([
            'bakery' => 'bakery',
            'bar' => 'bar',
            'cake_room' => 'cake_room',
            'cash_drawer' => 'till',
            'display_case' => 'till',
            'kitchen' => 'kitchen',
            'store' => 'store',
        ]);
})->group('feature', 'seeds');

it('creates the eight ingredient and six product categories', function () {
    seedDemoBakery();

    $names = fn (ItemKind $kind): array => Category::query()->where('kind', $kind)->orderBy('sort')->pluck('name_en')->all();
    expect($names(ItemKind::Ingredient))->toBe([
        'Flour & grains', 'Dairy & eggs', 'Sugar & sweeteners', 'Fats & oils', 'Meat & poultry', 'Vegetables',
        'Beverage bases (coffee, tea, juice)', 'Packaging',
    ])->and($names(ItemKind::Product))->toBe([
        'Breads', 'Cakes & pastries', 'Sandwiches', 'Pizza', 'Hot drinks', 'Cold drinks',
    ]);
})->group('feature', 'seeds');

it('stocks about 40 ingredients and 25 products with the kind, fulfilment, SKU, category, station and price of an Addis bakery-cafe', function () {
    seedDemoBakery();
    $items = demoItemsByName();
    $ingredients = array_filter($items, fn (Item $i): bool => $i->isIngredient());
    $products = array_filter($items, fn (Item $i): bool => $i->isProduct());

    expect(count($ingredients))->toBeGreaterThanOrEqual(40)
        ->and(count($products))->toBeGreaterThanOrEqual(25)
        ->and(array_filter($items, fn (Item $i): bool => $i->sku === null || $i->category_id === null || ! $i->is_active))->toBe([])
        ->and(array_filter($ingredients, fn (Item $i): bool => $i->fulfilment !== Fulfilment::BoughtIn))->toBe([]);

    $routing = app(Routing::class);
    $station = fn (string $name): ?string => $routing->stationFor($items[$name])?->code->value;
    $producedAt = fn (string $name): ?string => $routing->productionStationFor($items[$name])?->code->value;

    foreach (['Chicken sandwich', 'Tuna sandwich', 'Club sandwich', 'Beef burger', 'Margherita pizza', 'Special pizza'] as $name) {
        expect($items[$name]->fulfilment)->toBe(Fulfilment::ToOrder, $name)->and($station($name))->toBe('kitchen', $name);
    }
    foreach (['Macchiato', 'Latte', 'Cappuccino', 'Ethiopian coffee (buna)', 'Tea (shai)', 'Spris (layered juice)', 'Mango juice', 'Avocado juice', 'Papaya juice'] as $name) {
        expect($items[$name]->fulfilment)->toBe(Fulfilment::ToOrder, $name)->and($station($name))->toBe('bar', $name);
    }
    foreach (['Black forest cake (slice)', 'Black forest cake (whole)', 'Chocolate cake (slice)', 'Cheesecake (slice)'] as $name) {
        expect($items[$name]->fulfilment)->toBe(Fulfilment::ToStock, $name)
            ->and($items[$name]->default_batch_qty?->isPositive())->toBeTrue($name)
            ->and($producedAt($name))->toBe('cake_room', $name)
            ->and($station($name))->toBeNull($name);
    }
    foreach (['Bread roll', 'Baguette', 'Sweet bread (mulmul)', 'Croissant', 'Toast bread (slice)', 'Burger bun'] as $name) {
        expect($items[$name]->fulfilment)->toBe(Fulfilment::ToStock, $name)
            ->and($items[$name]->default_batch_qty?->isPositive())->toBeTrue($name)
            ->and($producedAt($name))->toBe('bakery', $name);
    }
    foreach (['Bottled water 0.5 L', 'Cola 300 ml', 'Orange soda 300 ml'] as $name) {
        expect($items[$name]->fulfilment)->toBe(Fulfilment::BoughtIn, $name)->and($producedAt($name))->toBeNull($name);
    }

    $prices = app(Prices::class);
    foreach ($products as $name => $product) {
        $price = $prices->current($product);
        expect($price)->not->toBeNull("{$name} has no current price")
            ->and($price?->currency())->toBe('ETB')
            ->and($price?->isPositive())->toBeTrue("{$name} is free");
    }
    expect($prices->current($items['Macchiato'])?->amount())->toBe('60.00')
        ->and($prices->current($items['Wheat flour']))->toBeNull('ingredients carry no selling price');
})->group('feature', 'seeds');

it('gives every made product an active v1 recipe whose lines resolve in base units, and bought-in products none', function () {
    seedDemoBakery();
    $recipes = app(Recipes::class);
    $units = app(Units::class);

    foreach (Item::query()->where('kind', ItemKind::Product)->get() as $product) {
        $recipe = $recipes->activeFor($product);
        expect($recipe)->not->toBeNull("{$product->name_en} has no recipe");
        if ($product->isBoughtIn()) {
            expect($recipe?->lines)->toBe([], "{$product->name_en} is bought in");

            continue;
        }
        expect($recipe?->version)->toBe(1)
            ->and($recipe?->yield->isPositive())->toBeTrue()
            ->and($recipe?->lines)->not->toBeEmpty("{$product->name_en} has an empty recipe");
        foreach ($recipe->lines ?? [] as $line) {
            $ingredient = Item::query()->findOrFail($line->ingredientItemId);
            expect($line->unit)->toBeIn(['g', 'ml', 'pcs'])
                ->and($line->unit)->toBe($units->baseOf($ingredient->unit_id)->code, "{$product->name_en}: {$ingredient->name_en}")
                ->and($line->qty->isPositive())->toBeTrue();
        }
    }
})->group('feature', 'seeds');

it('makes a sandwich from two slices of bread, itself a product, and a latte from milk, coffee beans and a cup', function () {
    seedDemoBakery();
    $items = demoItemsByName();
    $lines = function (string $product) use ($items): array {
        $out = [];
        foreach (app(Recipes::class)->activeFor($items[$product])->lines ?? [] as $line) {
            $out[Item::query()->findOrFail($line->ingredientItemId)->name_en] = [$line->qty->toString(), $line->unit];
        }

        return $out;
    };

    $sandwich = $lines('Chicken sandwich');
    expect($sandwich['Toast bread (slice)'])->toBe(['2.0000', 'pcs'])
        ->and($items['Toast bread (slice)']->isProduct())->toBeTrue();

    $latte = $lines('Latte');
    expect(array_keys($latte))->toContain('Fresh milk', 'Espresso coffee beans', 'Paper cup 8 oz')
        ->and($latte['Fresh milk'][1])->toBe('ml')
        ->and($latte['Espresso coffee beans'][1])->toBe('g')
        ->and($latte['Paper cup 8 oz'])->toBe(['1.0000', 'pcs']);
})->group('feature', 'seeds');

it('computes the availability of every product without errors, and opens with every product sellable', function () {
    seedDemoBakery();
    $availability = app(Availability::class);

    foreach (Item::query()->where('kind', ItemKind::Product)->get() as $product) {
        $location = demoSaleLocation($product);
        $units = $availability->ofProduct($product, $location);
        expect($units->isPositive())->toBeTrue("{$product->name_en} is not available at {$location->name_en}");
    }
})->group('feature', 'seeds');

it('writes the opening stock through the Ledger: sum(movements) == stock_balances == item_stock_view', function () {
    $branch = seedDemoBakery();
    $oracle = ledgerOracle($branch->id);

    expect($oracle['movements'])->not->toBeEmpty()
        ->and($oracle['balances'])->toBe($oracle['movements'])
        ->and($oracle['view'])->toBe($oracle['movements']);

    $admin = User::query()->where('phone', DemoBakery::ADMIN_PHONE)->sole();
    $movements = DB::table('stock_movements')->where('branch_id', $branch->id)->get();
    expect($movements->pluck('reason')->unique()->sort()->values()->all())->toBe(['count_adjustment', 'purchase_in', 'transfer'])
        ->and($movements->pluck('user_id')->unique()->values()->all())->toBe([$admin->id])
        ->and($movements->whereNull('client_uuid')->count())->toBe(0);

    // ingredients and bought-in products arrive at the store; every working location holds stock afterwards
    $store = Location::query()->where('type', LocationType::Store)->sole();
    $purchases = $movements->where('reason', 'purchase_in');
    expect($purchases->pluck('to_location_id')->unique()->values()->all())->toBe([$store->id]);
    $stock = app(Stock::class);
    foreach ([LocationType::Store, LocationType::Kitchen, LocationType::Bar, LocationType::CakeRoom, LocationType::Bakery, LocationType::DisplayCase] as $type) {
        $location = Location::query()->where('type', $type)->sole();
        expect($stock->at($location))->not->toBeEmpty("{$type->value} holds no stock");
    }
    expect($stock->of(demoItemsByName()['Wheat flour'], Location::query()->where('type', LocationType::Bakery)->sole())->onHand->toString())
        ->toBe('50000.0000');
})->group('feature', 'seeds');

it('names everything in English and Amharic', function () {
    seedDemoBakery();
    $bilingual = function (string $what, string $en, string $am): void {
        expect(preg_match('/[A-Za-z]/', $en))->toBe(1, "{$what}: English name '{$en}' has no Latin letters")
            ->and(preg_match('/\p{Ethiopic}/u', $am))->toBe(1, "{$what}: Amharic name '{$am}' has no Ge'ez letters")
            ->and($am)->not->toBe($en);
    };

    foreach (Station::query()->get() as $s) {
        $bilingual('station', $s->name_en, $s->name_am);
    }
    foreach (Location::query()->get() as $l) {
        $bilingual('location', $l->name_en, $l->name_am);
    }
    foreach (Category::query()->get() as $c) {
        $bilingual('category', $c->name_en, $c->name_am);
    }
    foreach (Item::query()->get() as $i) {
        $bilingual("item {$i->sku}", $i->name_en, $i->name_am);
    }
    foreach (Unit::query()->get() as $u) {
        $bilingual("unit {$u->code}", $u->name_en, $u->name_am);
    }
    $steps = RecipeStep::query()->get();
    expect($steps)->not->toBeEmpty();
    foreach ($steps as $step) {
        $bilingual('recipe step', $step->text_en, $step->text_am);
    }
    // a person's name has no translation: the one name column carries both scripts
    foreach (User::query()->get() as $u) {
        expect(preg_match('/^[^()]*[A-Za-z][^()]* \(\P{Latin}*\p{Ethiopic}[^()]*\)$/u', $u->name))->toBe(1, "user name '{$u->name}' is not 'Latin (Ge'ez)'");
    }
})->group('feature', 'seeds');

it('seeds one person per role, a second waiter for the shared pad, an overseer, stations and short codes', function () {
    seedDemoBakery();
    $users = User::query()->with(['roles', 'stationAssignments.station'])->get();

    expect($users)->toHaveCount(9);
    foreach ($users as $user) {
        expect($user->roles)->toHaveCount(1, "{$user->name} must hold exactly one role")
            ->and($user->phone)->toMatch('/^\+2519\d{8}$/')
            ->and($user->locale)->toBeIn(['en', 'am'])
            ->and($user->is_active)->toBeTrue()
            ->and($user->hasShortCode())->toBeTrue("{$user->name} has no short code");
    }
    $byRole = $users->groupBy(fn (User $u): string => (string) $u->roles->first()?->name);
    foreach (PermissionRegistry::ROLES as $role) {
        expect($byRole->get($role))->not->toBeNull("no {$role}");
    }
    expect($byRole->get('waiter'))->toHaveCount(2)
        ->and($byRole->get('admin')?->first()?->phone)->toBe(DemoBakery::ADMIN_PHONE);

    $counts = fn (string $role): array => $byRole->get($role)?->first()?->stationAssignments
        ->filter(fn (UserStationAssignment $a): bool => $a->can_count)
        ->map(fn (UserStationAssignment $a): string => $a->station->code->value)->values()->all() ?? [];
    expect($counts('chef'))->toBe(['kitchen'])
        ->and($counts('barista'))->toBe(['bar'])
        ->and($counts('cake_baker'))->toBe(['cake_room'])
        ->and($counts('bread_baker'))->toBe(['bakery'])
        ->and($counts('cashier'))->toBe(['till']);

    $overseers = UserStationAssignment::query()->where('is_overseer', true)->get();
    expect($overseers->pluck('user_id')->unique())->toHaveCount(1);
})->group('feature', 'seeds');

it('lets the seeded admin sign in through POST /api/v1/login with the default password in testing', function () {
    $branch = seedDemoBakery();
    app(CurrentBranch::class)->clear();

    $response = $this->postJson('/api/v1/login', ['phone' => DemoBakery::ADMIN_PHONE, 'password' => DemoCredentials::LOCAL_PASSWORD]);

    $response->assertOk();
    expect($response->json('user.branch_id'))->toBe($branch->id)
        ->and($response->json('permissions'))->toContain('catalog.manage', 'people.manage', 'inventory.adjust');
})->group('feature', 'seeds');

it('derives the short codes from the demo password, so a seeded waiter can open the shared pad', function () {
    $branch = seedDemoBakery();
    $waiter = collect(DemoBakery::PEOPLE)->firstWhere('role', 'waiter');
    $codes = DemoCredentials::shortCodes(array_column(DemoBakery::PEOPLE, 'phone'), DemoCredentials::LOCAL_PASSWORD);

    expect($codes)->toHaveCount(count(DemoBakery::PEOPLE))
        ->and(array_unique($codes))->toHaveCount(count(DemoBakery::PEOPLE));

    $user = app(ShortCodes::class)->verify($branch->id, $codes[$waiter['phone']], 'seeds-test-pad');
    expect($user->phone)->toBe($waiter['phone']);
})->group('feature', 'seeds');

it('records every seeded write in the demo branch audit log, with the demo admin as the actor', function () {
    $branch = seedDemoBakery();
    $admin = User::query()->where('phone', DemoBakery::ADMIN_PHONE)->sole();
    $audit = AuditLog::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get();

    expect($audit->pluck('action')->unique()->all())->toContain(
        'user.create_admin', 'station.create', 'location.create', 'category.create', 'item.create', 'item.price.set',
        'item.stations.replace', 'recipe.put', 'stock.opening.seed', 'user.create', 'user.short_code.set', 'user.stations.replace',
    )->and($audit->pluck('actor_id')->unique()->values()->all())->toBe([$admin->id])
        ->and(AuditLog::query()->withoutGlobalScopes()->where('branch_id', '!=', $branch->id)->count())->toBe(0);
})->group('feature', 'seeds');

it('is idempotent: a second run yields the same counts and writes nothing, not even an audit row', function () {
    $branch = seedDemoBakery();
    $first = DemoCounts::of($branch->id);
    $rows = rowsOfBranch($branch->id);
    app(CurrentBranch::class)->clear();

    seedDemoBakery();

    expect(Branch::query()->count())->toBe(1)
        ->and(DemoCounts::of($branch->id))->toBe($first)
        ->and(rowsOfBranch($branch->id))->toBe($rows);
    expect($first['items'])->toBe($first['ingredients'] + $first['products'])
        ->and($first['users'])->toBe(9)
        ->and($first['locations'])->toBe(7)
        ->and($first['stock_movements'])->toBeGreaterThan(0);
})->group('feature', 'seeds');

it('never touches another branch', function () {
    seedRoles();
    $other = Branch::factory()->create(['name' => 'Piazza']);
    actInBranch($other);
    $owner = makeUser($other, 'admin', ['phone' => DemoBakery::ADMIN_PHONE]);
    withActor($owner->id);
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $other->id, 'sku' => 'ING-FLR-WHT', 'name_en' => 'Wheat flour']);
    stockIn($flour, stockLocation($other, LocationType::Store), '5000.0000');
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();
    $before = rowsOfBranch($other->id);

    $demo = seedDemoBakery();

    expect($demo->id)->not->toBe($other->id)
        ->and(rowsOfBranch($other->id))->toBe($before);
    foreach (['items', 'users', 'stock_movements', 'audit_log', 'locations', 'stations'] as $table) {
        expect(DB::table($table)->whereNotIn('branch_id', [$demo->id, $other->id])->count())->toBe(0, $table);
    }
    // the same phone in two branches: login names the branch
    $this->postJson('/api/v1/login', ['phone' => DemoBakery::ADMIN_PHONE, 'password' => DemoCredentials::LOCAL_PASSWORD, 'branch_id' => $demo->id])
        ->assertOk();
})->group('feature', 'seeds');

it('refuses a branch of that name that is not a demo branch, and writes nothing', function () {
    $shop = Branch::factory()->create(['name' => DemoBakery::DEFAULT_BRANCH]);

    expect(fn () => app(Phase0Seeder::class)->run())->toThrow(DemoSeedRefused::class);

    expect(Branch::query()->count())->toBe(1)
        ->and(User::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(0)
        ->and(Station::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $shop->id)->count())->toBe(0);
})->group('feature', 'seeds');

it('seeds into the branch named by bakery.demo.branch', function () {
    config(['bakery.demo.branch' => 'Bole Demo']);

    app(Phase0Seeder::class)->run();

    $branch = Branch::query()->sole();
    expect($branch->name)->toBe('Bole Demo')
        ->and(Item::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->count())->toBeGreaterThan(60);
})->group('feature', 'seeds');

it('refuses to seed outside local and testing without BAKERY_DEMO_PASSWORD, and writes nothing', function (?string $password) {
    app()->detectEnvironment(fn (): string => 'production');
    config(['bakery.demo.password' => $password]);

    expect(fn () => app(Phase0Seeder::class)->run())->toThrow(DemoSeedRefused::class);

    expect(Branch::query()->count())->toBe(0)
        ->and(User::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(0);
})->with([
    'not set' => [null],
    'empty' => [''],
    'too short' => ['short'],
    'the documented local password' => [DemoCredentials::LOCAL_PASSWORD],
])->group('feature', 'seeds');

it('uses BAKERY_DEMO_PASSWORD for every demo account when it is set', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['bakery.demo.password' => 'a-long-demo-secret-2026']);

    seedDemoBakery();
    app(CurrentBranch::class)->clear();

    $this->postJson('/api/v1/login', ['phone' => DemoBakery::ADMIN_PHONE, 'password' => 'a-long-demo-secret-2026'])->assertOk();
    $this->postJson('/api/v1/login', ['phone' => DemoBakery::ADMIN_PHONE, 'password' => DemoCredentials::LOCAL_PASSWORD])->assertUnauthorized();
})->group('feature', 'seeds');
