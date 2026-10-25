<?php

declare(strict_types=1);

/*
 * Registry of every write endpoint under /api/v1 (rule 7, ADR 0003). tests/Invariants/IdempotencyCoverageTest.php
 * drives assertIdempotent() over every entry and fails when a write route exists that is not listed here.
 *
 * Each entry:
 *   [
 *     'method'  => 'POST',
 *     'uri'     => '/api/v1/users/{id}/stations',               // the literal route uri (placeholders kept)
 *     'module'  => 'identity',
 *     'table'   => 'users',                                     // the table that grows by exactly one row on the
 *                                                               // first request: the created row, or audit_log for
 *                                                               // updates (every write is one AuditedAction)
 *     'setup'   => fn (Tests\TestCase $t): array => [...],      // seed + loginAs(admin); returns ['{id}' => '7'] for
 *                                                               // placeholders (or an empty array)
 *     'payload' => fn (): array => [...],
 *   ]
 *
 * Every entry builds its own branch and admin so entries never collide on unique columns within one test run
 * (units.code is global: only one entry creates a unit).
 */

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Shared\Enums\StationCode;
use App\Shared\Files\Files;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\NotificationDrivers;
use Tests\Support\OrdersMenu;
use Tests\Support\ProductionFixture;
use Tests\Support\Shifts;
use Tests\Support\ShiftsFixture;
use Tests\TestCase;

$admin = static function (): User {
    seedRoles();
    $branch = Branch::factory()->create();
    $admin = makeUser($branch, 'admin');
    actInBranch($branch);

    return loginAs($admin);
};

// a display-case product of the admin's branch the till can sell at once: priced an hour ago, 10 in the display case
$orderableCake = static function (User $me): Item {
    withActor($me->id);
    $display = Location::factory()->ofType(LocationType::DisplayCase)->create(['branch_id' => $me->branch_id]);
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-ORDER-CAKE']);
    app(PriceHistory::class)->open($cake, Money::of('45.00'), CarbonImmutable::now()->subHour()->startOfSecond());
    stockIn($cake, $display, '10');

    return $cake;
};

// a chicken sandwich sent to the kitchen of the admin's branch (Tests\Support\OrdersMenu): the id of its queued line,
// which the admin works on (stations.all), readied first when $ready
$sentSandwichLine = static function (TestCase $t, User $me, bool $ready = false): string {
    withActor($me->id);
    $menu = OrdersMenu::build(currentBranch());
    $order = $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $menu->order([$menu->line($menu->sandwich)]));
    $lineId = (string) $order->json('lines.0.id');
    if ($ready) {
        $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/order-lines/{$lineId}/ready")->assertOk();
    }

    return $lineId;
};

// a flour counted in kilograms (IDEM-PURCHASE-FLOUR), a store and a supplier in the admin current branch, for the purchase endpoints
$purchasable = static function (User $me): void {
    withActor($me->id);
    Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-PURCHASE-FLOUR']);
    Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $me->branch_id]);
    Supplier::factory()->create(['branch_id' => $me->branch_id]);
};

// a flour counted in kilograms (IDEM-TRANSFER-FLOUR) with a store and a kitchen in the admin current branch, for the transfer endpoints
$transferable = static function (User $me): Item {
    withActor($me->id);
    Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $me->branch_id]);
    Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $me->branch_id]);

    return Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-TRANSFER-FLOUR']);
};

// the cake room of a branch with its people and stock (Tests\Support\ProductionFixture), the admin of that branch logged in
$productionFloor = static function () use ($admin): ProductionFixture {
    $admin();
    $f = ProductionFixture::build(currentBranch());
    $f->stockCakeRoom();
    loginAs($f->admin);

    return $f;
};

// the stations and people of a shift floor (Tests\Support\ShiftsFixture) in the admin's branch, the fixture's admin logged in
$shiftsFloor = static function (TestCase $t) use ($admin): ShiftsFixture {
    $admin();
    $f = ShiftsFixture::build(currentBranch(), $t);
    loginAs($f->admin);

    return $f;
};

return [
    [
        'method' => 'POST',
        'uri' => '/api/v1/logout',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/users',
        'module' => 'identity',
        'table' => 'users',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => [
            'name' => 'Idempotent Waiter',
            'phone' => '+251911777001',
            'password' => 'password-123',
            'locale' => 'am',
            'role' => 'waiter',
        ],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/users/{id}',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $target = makeUser($me->branch, 'cashier');

            return ['{id}' => (string) $target->id];
        },
        'payload' => fn (): array => ['name' => 'Renamed Cashier', 'locale' => 'am'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/users/{id}/short-code/reset',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $target = makeUser($me->branch, 'waiter');

            return ['{id}' => (string) $target->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/users/{id}/stations',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $target = makeUser($me->branch, 'chef');
            Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $me->branch_id]);

            return ['{id}' => (string) $target->id];
        },
        // the kitchen created by setup, read in the admin's (current) branch
        'payload' => fn (): array => ['assignments' => [[
            'station_id' => Station::query()->where('code', StationCode::Kitchen)->latest('id')->firstOrFail()->id,
            'can_count' => true,
            'is_overseer' => false,
        ]]],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stations',
        'module' => 'identity',
        'table' => 'stations',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['code' => 'bar', 'name_en' => 'Bar', 'name_am' => 'ባር', 'requires_overseer' => true, 'sort' => 2],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/stations/{station}',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $station = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $me->branch_id]);

            return ['{station}' => (string) $station->id];
        },
        'payload' => fn (): array => ['requires_overseer' => true, 'sort' => 4],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/devices',
        'module' => 'identity',
        'table' => 'devices',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['name' => 'Pad 1', 'fingerprint' => 'idempotent-pad-fingerprint-1'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/devices/{id}/revoke',
        'module' => 'identity',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $device = Device::factory()->create(['branch_id' => $me->branch_id, 'enrolled_by' => $me->id]);

            return ['{id}' => (string) $device->id];
        },
        'payload' => fn (): array => [],
    ],

    // Catalog (step 0.5)
    [
        'method' => 'POST',
        'uri' => '/api/v1/units',
        'module' => 'catalog',
        'table' => 'units',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['code' => 'idem_sack', 'name_en' => 'Sack (50 kg)', 'name_am' => 'ጆንያ', 'base_unit_id' => catalogUnit('g')->id, 'factor' => '50000'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/categories',
        'module' => 'catalog',
        'table' => 'categories',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['kind' => 'ingredient', 'name_en' => 'Dairy', 'name_am' => 'የወተት ተዋጽኦ', 'sort' => 2],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/categories/{category}',
        'module' => 'catalog',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $category = Category::factory()->create(['branch_id' => $me->branch_id]);

            return ['{category}' => (string) $category->id];
        },
        'payload' => fn (): array => ['name_en' => 'Breads', 'name_am' => 'ዳቦዎች'],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/categories/{category}',
        'module' => 'catalog',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $category = Category::factory()->create(['branch_id' => $me->branch_id]);

            return ['{category}' => (string) $category->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/items',
        'module' => 'catalog',
        'table' => 'items',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['kind' => 'ingredient', 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'unit_id' => catalogUnit('kg')->id, 'sku' => 'IDEM-FLOUR'],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/items/{item}',
        'module' => 'catalog',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $item = Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $item->id];
        },
        'payload' => fn (): array => ['name_en' => 'Wheat flour', 'is_active' => false],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/items/{item}',
        'module' => 'catalog',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $item = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $item->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/items/{item}/prices',
        'module' => 'catalog',
        'table' => 'item_prices',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $item->id];
        },
        'payload' => fn (): array => ['price' => '45.00'],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/items/{item}/recipe',
        'module' => 'catalog',
        'table' => 'recipes',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-RECIPE-FLOUR']);
            $bread = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $bread->id];
        },
        // the flour created by setup, read in the admin's (current) branch
        'payload' => fn (): array => [
            'yield_qty' => '40',
            'yield_unit_id' => catalogUnit('pcs')->id,
            'lines' => [[
                'ingredient_item_id' => Item::query()->where('sku', 'IDEM-RECIPE-FLOUR')->latest('id')->firstOrFail()->id,
                'qty' => '5',
                'unit_id' => catalogUnit('kg')->id,
            ]],
        ],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/items/{item}/recipe/versions',
        'module' => 'catalog',
        'table' => 'recipes',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-PUBLISH-FLOUR']);
            $bread = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);
            // version 1, as PUT /items/{item}/recipe makes it: a publish needs a base to change
            $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->putJson("/api/v1/items/{$bread->id}/recipe", [
                'yield_qty' => '40', 'yield_unit_id' => catalogUnit('pcs')->id,
                'lines' => [['ingredient_item_id' => $flour->id, 'qty' => '5', 'unit_id' => catalogUnit('kg')->id]],
            ])->assertCreated();

            return ['{item}' => (string) $bread->id];
        },
        // one kilogram more flour than version 1: a changed recipe, so a new version is the one row that grows
        'payload' => fn (): array => [
            'yield_qty' => '40',
            'yield_unit_id' => catalogUnit('pcs')->id,
            'lines' => [[
                'ingredient_item_id' => Item::query()->where('sku', 'IDEM-PUBLISH-FLOUR')->latest('id')->firstOrFail()->id,
                'qty' => '6',
                'unit_id' => catalogUnit('kg')->id,
            ]],
            'change_note' => 'More flour',
        ],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/items/{item}/stations',
        'module' => 'catalog',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $me->branch_id]);
            $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $latte->id];
        },
        // the bar created by setup, read in the admin's (current) branch
        'payload' => fn (): array => ['station_ids' => [Station::query()->where('code', StationCode::Bar)->latest('id')->firstOrFail()->id]],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/items/{item}/modifiers',
        'module' => 'catalog',
        'table' => 'modifiers',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $latte->id];
        },
        'payload' => fn (): array => ['name_en' => 'Large', 'name_am' => 'ትልቅ', 'price_delta' => '10.00'],
    ],

    // Inventory (step 0.6)
    [
        'method' => 'POST',
        'uri' => '/api/v1/locations',
        'module' => 'inventory',
        'table' => 'locations',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['name_en' => 'Display case', 'name_am' => 'ማሳያ', 'type' => 'display_case'],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/locations/{location}',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $location = Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $me->branch_id]);

            return ['{location}' => (string) $location->id];
        },
        'payload' => fn (): array => ['name_en' => 'Main kitchen', 'name_am' => 'ዋና ኩሽና'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stock/movements',
        'module' => 'inventory',
        'table' => 'stock_movements',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-STOCK-FLOUR']);
            Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $me->branch_id, 'name_en' => 'Idempotent store']);

            return [];
        },
        // the flour and the store created by setup, read in the admin's (current) branch
        'payload' => fn (): array => [
            'item_id' => Item::query()->where('sku', 'IDEM-STOCK-FLOUR')->latest('id')->firstOrFail()->id,
            'location_id' => Location::query()->where('name_en', 'Idempotent store')->latest('id')->firstOrFail()->id,
            'reason' => 'purchase_in',
            'qty' => '25',
        ],
    ],

    // Orders (step 1.3b): a priced display-case product with stock (IDEM-ORDER-CAKE) in the admin's branch; the
    // first request writes one orders row (create) or one audit_log row (the AuditedAction of every other write)
    [
        'method' => 'POST',
        'uri' => '/api/v1/orders',
        'module' => 'orders',
        'table' => 'orders',
        'setup' => function (TestCase $t) use ($admin, $orderableCake): array {
            $orderableCake($admin());

            return [];
        },
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'takeaway',
            'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => Item::query()->where('sku', 'IDEM-ORDER-CAKE')->latest('id')->firstOrFail()->id, 'qty' => '2']],
        ],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/orders/{order}/lines',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin, $orderableCake): array {
            $me = $admin();
            $orderableCake($me);
            $order = Order::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{order}' => (string) $order->id];
        },
        'payload' => fn (): array => [
            'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => Item::query()->where('sku', 'IDEM-ORDER-CAKE')->latest('id')->firstOrFail()->id, 'qty' => '1']],
        ],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/orders/{order}/send',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin, $orderableCake): array {
            $cake = $orderableCake($admin());
            $order = $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
                'client_uuid' => (string) Str::uuid(),
                'type' => 'takeaway',
                'send' => false,
                'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => $cake->id, 'qty' => '1']],
            ]);

            return ['{order}' => (string) $order->json('id')];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/orders/{order}/cancel',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $order = Order::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{order}' => (string) $order->id];
        },
        'payload' => fn (): array => ['reason' => 'The customer left'],
    ],

    [
        'method' => 'POST',
        'uri' => '/api/v1/orders/{order}/void',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            app(ShortCodes::class)->set($me, '864209');
            $order = Order::factory()->sent()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{order}' => (string) $order->id];
        },
        'payload' => fn (): array => ['reason' => 'Customer left', 'pin' => '864209'],
    ],

    // Station actions (step 1.3c): one AuditedAction each, so the first request writes one audit_log row
    [
        'method' => 'POST',
        'uri' => '/api/v1/order-lines/{line}/accept',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => fn (TestCase $t): array => ['{line}' => $sentSandwichLine($t, $admin())],
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/order-lines/{line}/ready',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => fn (TestCase $t): array => ['{line}' => $sentSandwichLine($t, $admin())],
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/order-lines/{line}/reject',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => fn (TestCase $t): array => ['{line}' => $sentSandwichLine($t, $admin())],
        'payload' => fn (): array => ['reason' => 'equipment_down'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/order-lines/{line}/served',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => fn (TestCase $t): array => ['{line}' => $sentSandwichLine($t, $admin(), true)],
        'payload' => fn (): array => [],
    ],
    // Dining tables (step 1.3e): one AuditedAction each; the create adds the table row
    [
        'method' => 'POST',
        'uri' => '/api/v1/dining-tables',
        'module' => 'orders',
        'table' => 'dining_tables',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['code' => 'IDEM-T1', 'name_en' => 'Table 1', 'name_am' => 'ጠረጴዛ 1', 'seats' => 4],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/dining-tables/{table}',
        'module' => 'orders',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();

            return ['{table}' => (string) DiningTable::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id])->id];
        },
        'payload' => fn (): array => ['name_en' => 'Window', 'name_am' => 'መስኮት', 'sort' => 3],
    ],
    // Payments (step 1.4): a cash payment of a sent order (two cakes at 45.00) for the whole balance; the first request
    // writes one payments row (the AuditedAction's audit row comes with it)
    [
        'method' => 'POST',
        'uri' => '/api/v1/orders/{order}/payments',
        'module' => 'payments',
        'table' => 'payments',
        'setup' => function (TestCase $t) use ($admin, $orderableCake): array {
            $me = $admin();
            $cake = $orderableCake($me);
            // payments.require_shift_for_cash (on by default since step 3.7e): the admin takes the cash at a till whose shift runs
            $till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $me->branch_id]);
            Shifts::ensureOpen($till);
            $t->withHeader('X-Station-Id', (string) $till->id);
            $order = $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
                'client_uuid' => (string) Str::uuid(),
                'type' => 'takeaway',
                'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => $cake->id, 'qty' => '2']],
            ]);

            return ['{order}' => (string) $order->json('id')];
        },
        'payload' => fn (): array => ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '90.00', 'tendered_amount' => '100.00'],
    ],
    // Split bills (step 4.3b): a batch of one cash row for the whole balance of the same order; the first request writes one payments row
    // (the batch's one AuditedAction comes with it)
    [
        'method' => 'POST',
        'uri' => '/api/v1/orders/{order}/payment-batches',
        'module' => 'payments',
        'table' => 'payments',
        'setup' => function (TestCase $t) use ($admin, $orderableCake): array {
            $me = $admin();
            $cake = $orderableCake($me);
            $till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $me->branch_id]);
            Shifts::ensureOpen($till);
            $t->withHeader('X-Station-Id', (string) $till->id);
            $order = $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
                'client_uuid' => (string) Str::uuid(),
                'type' => 'takeaway',
                'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => $cake->id, 'qty' => '2']],
            ]);

            return ['{order}' => (string) $order->json('id')];
        },
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(),
            'rows' => [['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '90.00', 'tendered_amount' => '100.00']],
        ],
    ],

    // Payment channels (step 4.3a): a create writes the channel row; an edit, the reorder and the soft delete write one audit_log row each
    [
        'method' => 'POST',
        'uri' => '/api/v1/payment-channels',
        'module' => 'payments',
        'table' => 'payment_channels',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(), 'code' => 'idem_wallet', 'name_en' => 'Idempotent wallet', 'name_am' => 'ቦርሳ',
            'requires_photo' => true, 'requires_reference' => true, 'reference_pattern' => '[A-Z0-9]{10}',
        ],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/payment-channels/{channel}',
        'module' => 'payments',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return ['{channel}' => (string) PaymentChannel::query()->where('code', 'telebirr')->value('id')];
        },
        'payload' => fn (): array => ['name_en' => 'Telebirr wallet', 'name_am' => 'ቴሌብር ቦርሳ', 'reference_pattern' => '\d{10}'],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/payment-channels/order',
        'module' => 'payments',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        // every live channel of the admin's branch, last first
        'payload' => fn (): array => ['ids' => PaymentChannel::query()->orderByDesc('sort')->orderByDesc('id')->pluck('id')->all()],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/payment-channels/{channel}',
        'module' => 'payments',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return ['{channel}' => (string) PaymentChannel::query()->where('code', 'coop')->value('id')];
        },
        'payload' => fn (): array => [],
    ],

    // Suppliers and purchases (step 2.2b)
    [
        'method' => 'POST',
        'uri' => '/api/v1/suppliers',
        'module' => 'inventory',
        'table' => 'suppliers',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();

            return [];
        },
        'payload' => fn (): array => ['name' => 'Idempotent Mill', 'phone' => '+251911777002'],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/suppliers/{supplier}',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            withActor($me->id);
            $supplier = Supplier::factory()->create(['branch_id' => $me->branch_id]);

            return ['{supplier}' => (string) $supplier->id];
        },
        'payload' => fn (): array => ['name' => 'Renamed Mill', 'is_active' => false],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/purchases',
        'module' => 'inventory',
        'table' => 'purchases',
        'setup' => function (TestCase $t) use ($admin, $purchasable): array {
            $purchasable($admin());

            return [];
        },
        // the supplier and the flour created by setup, read in the admin current branch
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(),
            'supplier_id' => Supplier::query()->latest('id')->firstOrFail()->id,
            'invoice_no' => 'IDEM-INV-1',
            'purchase_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'client_uuid' => (string) Str::uuid(),
                'item_id' => Item::query()->where('sku', 'IDEM-PURCHASE-FLOUR')->latest('id')->firstOrFail()->id,
                'qty' => '20',
                'unit_id' => catalogUnit('kg')->id,
                'unit_cost' => '80',
            ]],
        ],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/purchases/{purchase}/lines',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin, $purchasable): array {
            $me = $admin();
            $purchasable($me);
            $purchase = Purchase::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'supplier_id' => Supplier::query()->latest('id')->firstOrFail()->id]);

            return ['{purchase}' => (string) $purchase->id];
        },
        'payload' => fn (): array => [
            'lines' => [[
                'client_uuid' => (string) Str::uuid(),
                'item_id' => Item::query()->where('sku', 'IDEM-PURCHASE-FLOUR')->latest('id')->firstOrFail()->id,
                'qty' => '5',
                'unit_id' => catalogUnit('kg')->id,
                'unit_cost' => '90',
            ]],
        ],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/purchases/{purchase}/confirm',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin, $purchasable): array {
            $me = $admin();
            $purchasable($me);
            $draft = $t->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/purchases', [
                'client_uuid' => (string) Str::uuid(),
                'supplier_id' => Supplier::query()->latest('id')->firstOrFail()->id,
                'purchase_date' => CarbonImmutable::now()->toDateString(),
                'lines' => [[
                    'client_uuid' => (string) Str::uuid(),
                    'item_id' => Item::query()->where('sku', 'IDEM-PURCHASE-FLOUR')->latest('id')->firstOrFail()->id,
                    'qty' => '20',
                    'unit_id' => catalogUnit('kg')->id,
                    'unit_cost' => '80',
                ]],
            ]);

            return ['{purchase}' => (string) $draft->json('id')];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/purchases/{purchase}/cancel',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            withActor($me->id);
            $purchase = Purchase::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{purchase}' => (string) $purchase->id];
        },
        'payload' => fn (): array => ['reason' => 'Not delivered'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/inventory/costs/{item}/opening',
        'module' => 'inventory',
        'table' => 'item_costs',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $item = Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);

            return ['{item}' => (string) $item->id];
        },
        'payload' => fn (): array => ['unit_cost' => '80', 'unit_id' => catalogUnit('kg')->id],
    ],

    // Notifications (step 2.5a): marking a line read is one AuditedAction, so the audit log is the table that grows
    [
        'method' => 'POST',
        'uri' => '/api/v1/notifications/{notification}/read',
        'module' => 'notifications',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $line = Notification::factory()->create(['branch_id' => $me->branch_id, 'user_id' => $me->id]);

            return ['{notification}' => (string) $line->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/notifications/read-all',
        'module' => 'notifications',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            Notification::factory()->count(2)->create(['branch_id' => $me->branch_id, 'user_id' => $me->id]);

            return [];
        },
        'payload' => fn (): array => [],
    ],
    // Notifications (step 2.5b): a push subscription creates one endpoint row; revoking and asking for a Telegram code are one AuditedAction each
    [
        'method' => 'POST',
        'uri' => '/api/v1/notifications/endpoints',
        'module' => 'notifications',
        'table' => 'notification_endpoints',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();
            NotificationDrivers::allOff();
            NotificationDrivers::push();

            return [];
        },
        'payload' => fn (): array => [
            'driver' => 'push',
            'subscription' => ['endpoint' => 'https://push.example.test/send/idem', 'keys' => ['p256dh' => 'BExamplePublicKeyOfTheBrowser', 'auth' => 'exampleAuthSecret']],
        ],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/notifications/endpoints/{endpoint}',
        'module' => 'notifications',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $endpoint = NotificationEndpoint::factory()->push()->create(['branch_id' => $me->branch_id, 'user_id' => $me->id]);

            return ['{endpoint}' => (string) $endpoint->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/notifications/telegram/link',
        'module' => 'notifications',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $admin();
            NotificationDrivers::allOff();
            NotificationDrivers::telegram();

            return [];
        },
        'payload' => fn (): array => [],
    ],
    // Transfers (step 2.3)
    [
        'method' => 'POST',
        'uri' => '/api/v1/transfers',
        'module' => 'inventory',
        'table' => 'transfers',
        'setup' => function (TestCase $t) use ($admin, $transferable): array {
            $transferable($admin());

            return [];
        },
        // the flour, the store and the kitchen created by setup, read in the admin current branch
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(),
            'item_id' => Item::query()->where('sku', 'IDEM-TRANSFER-FLOUR')->latest('id')->firstOrFail()->id,
            'to_location_id' => Location::query()->where('type', LocationType::Kitchen->value)->latest('id')->firstOrFail()->id,
            'qty' => '2',
            'unit_id' => catalogUnit('kg')->id,
        ],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/transfers/{transfer}/confirm',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin, $transferable): array {
            $me = $admin();
            $flour = $transferable($me);
            $store = Location::query()->where('type', LocationType::Store->value)->latest('id')->firstOrFail();
            stockIn($flour, $store, '5000');
            $transfer = Transfer::factory()->create([
                'branch_id' => $me->branch_id, 'item_id' => $flour->id, 'from_location_id' => $store->id,
                'to_location_id' => Location::query()->where('type', LocationType::Kitchen->value)->latest('id')->firstOrFail()->id,
                'qty' => '2000', 'created_by_user_id' => $me->id, 'requested_by_user_id' => $me->id,
            ]);

            return ['{transfer}' => (string) $transfer->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/transfers/{transfer}/reject',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin, $transferable): array {
            $me = $admin();
            $flour = $transferable($me);
            $transfer = Transfer::factory()->create([
                'branch_id' => $me->branch_id, 'item_id' => $flour->id,
                'from_location_id' => Location::query()->where('type', LocationType::Store->value)->latest('id')->firstOrFail()->id,
                'to_location_id' => Location::query()->where('type', LocationType::Kitchen->value)->latest('id')->firstOrFail()->id,
                'created_by_user_id' => $me->id, 'requested_by_user_id' => $me->id,
            ]);

            return ['{transfer}' => (string) $transfer->id];
        },
        'payload' => fn (): array => ['reason' => 'Not today'],
    ],
    // Reorder rules (step 2.6a)
    [
        'method' => 'POST',
        'uri' => '/api/v1/reorder-rules',
        'module' => 'inventory',
        'table' => 'reorder_rules',
        'setup' => function (TestCase $t) use ($admin, $transferable): array {
            $transferable($admin());

            return [];
        },
        // the flour and the store created by setup, read in the admin current branch
        'payload' => fn (): array => [
            'item_id' => Item::query()->where('sku', 'IDEM-TRANSFER-FLOUR')->latest('id')->firstOrFail()->id,
            'location_id' => Location::query()->where('type', LocationType::Store->value)->latest('id')->firstOrFail()->id,
            'min_qty' => '5000',
            'reorder_qty' => '25000',
            'notify_roles' => ['admin', 'manager'],
        ],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/reorder-rules/{rule}',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            withActor($me->id);
            $rule = ReorderRule::factory()->create(['branch_id' => $me->branch_id]);

            return ['{rule}' => (string) $rule->id];
        },
        'payload' => fn (): array => ['min_qty' => '1234', 'is_active' => false],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/reorder-rules/{rule}',
        'module' => 'inventory',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            withActor($me->id);
            $rule = ReorderRule::factory()->create(['branch_id' => $me->branch_id]);

            return ['{rule}' => (string) $rule->id];
        },
        'payload' => fn (): array => [],
    ],
    // Production requests (step 2.7b)
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/requests',
        'module' => 'production',
        'table' => 'production_requests',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            withActor($me->id);
            $station = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $me->branch_id]);
            $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id]);
            $cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create([
                'branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'sku' => 'IDEM-PRODUCTION-CAKE', 'production_station_id' => $station->id,
            ]);
            $recipe = Recipe::factory()->create(['branch_id' => $me->branch_id, 'product_item_id' => $cake->id, 'yield_qty' => '12']);
            RecipeLine::factory()->create(['branch_id' => $me->branch_id, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $flour->id, 'qty' => '1', 'unit_id' => catalogUnit('kg')->id]);

            return [];
        },
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(),
            'product_item_id' => Item::query()->where('sku', 'IDEM-PRODUCTION-CAKE')->latest('id')->firstOrFail()->id,
        ],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/requests/{request}/assign',
        'module' => 'production',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $station = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $me->branch_id]);
            $request = ProductionRequest::factory()->create(['branch_id' => $me->branch_id, 'station_id' => $station->id, 'created_by_user_id' => $me->id, 'requested_by_user_id' => $me->id]);
            $baker = makeUser($me->branch, 'cake_baker');
            UserStationAssignment::factory()->create(['branch_id' => $me->branch_id, 'user_id' => $baker->id, 'station_id' => $station->id]);

            return ['{request}' => (string) $request->id];
        },
        // the baker setup assigned to the cake room, read in the admin current branch
        'payload' => fn (): array => ['user_id' => UserStationAssignment::query()->latest('id')->firstOrFail()->user_id],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/requests/{request}/accept',
        'module' => 'production',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $request = ProductionRequest::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'requested_by_user_id' => $me->id]);
            // this registry entry counts one audit row per write; with the call-in on (the default since step 3.7e) the accept also opens or joins a
            // shift, which writes its own audit row (CallInTest and the Shifts endpoint tests cover that), so the call-in is off for this entry
            config(['shifts.open_ad_hoc_on_accept' => false]);

            return ['{request}' => (string) $request->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/requests/{request}/cancel',
        'module' => 'production',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            $me = $admin();
            $request = ProductionRequest::factory()->create(['branch_id' => $me->branch_id, 'created_by_user_id' => $me->id, 'requested_by_user_id' => $me->id]);

            return ['{request}' => (string) $request->id];
        },
        'payload' => fn (): array => ['reason' => 'Not needed today'],
    ],
    // Production batches (step 2.7c)
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/requests/{request}/start',
        'module' => 'production',
        'table' => 'production_batches',
        'setup' => function (TestCase $t) use ($productionFloor): array {
            $f = $productionFloor();

            return ['{request}' => (string) $f->acceptedRequest($f->admin)->id];
        },
        'payload' => fn (): array => ['client_uuid' => (string) Str::uuid()],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/batches/{batch}/done',
        'module' => 'production',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($productionFloor): array {
            $f = $productionFloor();

            return ['{batch}' => (string) $f->startedBatch($f->admin)->id];
        },
        'payload' => fn (): array => ['produced_qty' => '10', 'waste_qty' => '2'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/batches/{batch}/confirm',
        'module' => 'production',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($productionFloor): array {
            $f = $productionFloor();
            $f->requireOverseer();

            return ['{batch}' => (string) $f->finishedBatch($f->baker)->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/production/batches/{batch}/send-back',
        'module' => 'production',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($productionFloor): array {
            $f = $productionFloor();
            $f->requireOverseer();

            return ['{batch}' => (string) $f->finishedBatch($f->baker)->id];
        },
        'payload' => fn (): array => ['note' => 'Count the tray again'],
    ],
    // Shifts (step 3.2b): templates, scheduling, roster, cancel, start, call-in, join and leave
    [
        'method' => 'POST',
        'uri' => '/api/v1/shift-templates',
        'module' => 'shifts',
        'table' => 'shift_templates',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $shiftsFloor($t);

            return [];
        },
        'payload' => fn (): array => [
            'name_en' => 'Day', 'name_am' => 'ቀን', 'start_time' => '06:00', 'end_time' => '14:00', 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'station_ids' => [Station::query()->where('code', StationCode::Till->value)->latest('id')->firstOrFail()->id],
        ],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/shift-templates/{template}',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{template}' => (string) $f->template([$f->till])->id];
        },
        'payload' => fn (): array => ['name_en' => 'Early day', 'sort' => 2],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/shift-templates/{template}',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{template}' => (string) $f->template([$f->till])->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/shifts',
        'module' => 'shifts',
        'table' => 'shift_instances',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $f->template([$f->till]);

            return [];
        },
        'payload' => fn (): array => [
            'client_uuid' => (string) Str::uuid(),
            'station_id' => Station::query()->where('code', StationCode::Till->value)->latest('id')->firstOrFail()->id,
            'template_id' => ShiftTemplate::query()->latest('id')->firstOrFail()->id,
            'date' => '2026-11-02',
            'user_ids' => [],
        ],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/shifts/{shift}/roster',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{shift}' => (string) $f->scheduled($f->till, $f->template([$f->till]), '2026-11-02')->id];
        },
        'payload' => fn (): array => ['user_ids' => [UserStationAssignment::query()->where('station_id', Station::query()->where('code', StationCode::Till->value)->latest('id')->firstOrFail()->id)->orderBy('id')->firstOrFail()->user_id]],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/shifts/{shift}/cancel',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{shift}' => (string) $f->scheduled($f->till, $f->template([$f->till]), '2026-11-02')->id];
        },
        'payload' => fn (): array => ['reason' => 'Holiday'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stations/{station}/shifts/start',
        'module' => 'shifts',
        'table' => 'shift_instances',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{station}' => (string) $f->till->id];
        },
        'payload' => fn (): array => ['client_uuid' => (string) Str::uuid()],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stations/{station}/shifts/open-ad-hoc',
        'module' => 'shifts',
        'table' => 'shift_instances',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{station}' => (string) $f->kitchen->id];
        },
        'payload' => fn (): array => ['client_uuid' => (string) Str::uuid()],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/shifts/{shift}/join',
        'module' => 'shifts',
        'table' => 'shift_roster',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{shift}' => (string) $f->running($f->till, $f->cashier)->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/shifts/{shift}/leave',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $shift = $f->running($f->till, $f->cashier);
            $f->onRoster($shift, $f->admin);

            return ['{shift}' => (string) $shift->id];
        },
        'payload' => fn (): array => [],
    ],
    // Counts (step 3.3a): variance reasons, close, the lines of a count and its submit
    [
        'method' => 'POST',
        'uri' => '/api/v1/variance-reasons',
        'module' => 'shifts',
        'table' => 'variance_reasons',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $shiftsFloor($t);

            return [];
        },
        'payload' => fn (): array => ['code' => 'spilled', 'name_en' => 'Spilled', 'name_am' => 'ፈሰሰ'],
    ],
    [
        'method' => 'PATCH',
        'uri' => '/api/v1/variance-reasons/{reason}',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{reason}' => (string) $f->reasonId('miscount')];
        },
        'payload' => fn (): array => ['name_en' => 'Counting mistake'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/shifts/{shift}/close',
        'module' => 'shifts',
        'table' => 'stock_counts',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $f->place($f->kitchen);

            return ['{shift}' => (string) $f->running($f->kitchen, $f->chef)->id];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/stock-counts/{count}/lines',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $item = $f->ingredient();
            $f->stock($item, $f->place($f->kitchen), '100');
            $shift = $f->running($f->kitchen, $f->chef);
            loginAs($f->admin);

            return ['{count}' => (string) $f->close($f->chef, $shift->id)->json('counts.0.id')];
        },
        'payload' => fn (): array => ['confirm_all' => true],
    ],
    [
        'method' => 'PUT',
        'uri' => '/api/v1/stock-counts/{count}/cash',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $f->drawer($f->till);
            loginAs($f->admin);

            return ['{count}' => (string) collect($f->start($f->cashier, $f->till)->json('counts'))->firstWhere('location.type', 'cash_drawer')['id']];
        },
        'payload' => fn (): array => ['denominations' => ['100' => 2]],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stock-counts/{count}/submit',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $item = $f->ingredient();
            $f->stock($item, $f->place($f->kitchen), '100');
            $shift = $f->running($f->kitchen, $f->chef);
            $count = (int) $f->close($f->chef, $shift->id)->json('counts.0.id');
            $f->lines($f->chef, $count, ['confirm_all' => true])->assertOk();
            loginAs($f->admin);

            return ['{count}' => (string) $count];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stock-counts/{count}/approve',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $item = $f->ingredient();
            $f->stock($item, $f->place($f->kitchen), '100');
            $shift = $f->running($f->kitchen, $f->chef);
            $count = (int) $f->close($f->chef, $shift->id)->json('counts.0.id');
            $f->lines($f->chef, $count, ['lines' => [['item_id' => $item->id, 'counted_qty' => '90', 'reason_id' => $f->reasonId('spoilage')]]])->assertOk();
            $f->submit($f->chef, $count)->assertOk();
            loginAs($f->admin);

            return ['{count}' => (string) $count];
        },
        'payload' => fn (): array => [],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stock-counts/{count}/reject',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $item = $f->ingredient();
            $f->stock($item, $f->place($f->kitchen), '100');
            $shift = $f->running($f->kitchen, $f->chef);
            $count = (int) $f->close($f->chef, $shift->id)->json('counts.0.id');
            $f->lines($f->chef, $count, ['lines' => [['item_id' => $item->id, 'counted_qty' => '90', 'reason_id' => $f->reasonId('spoilage')]]])->assertOk();
            $f->submit($f->chef, $count)->assertOk();
            loginAs($f->admin);

            return ['{count}' => (string) $count];
        },
        'payload' => fn (): array => ['note' => 'Count the flour again'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stock-counts/{count}/cosign',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $f->kitchen->forceFill(['requires_overseer' => true])->save();
            $item = $f->ingredient();
            $f->stock($item, $f->place($f->kitchen), '100');
            $shift = $f->running($f->kitchen, $f->chef);
            $count = (int) $f->close($f->chef, $shift->id)->json('counts.0.id');
            $f->lines($f->chef, $count, ['lines' => [['item_id' => $item->id, 'counted_qty' => '90', 'reason_id' => $f->reasonId('spoilage')]]])->assertOk();
            $f->submit($f->chef, $count)->assertOk();
            loginAs($f->admin);

            return ['{count}' => (string) $count];
        },
        'payload' => fn (): array => ['note' => 'Seen'],
    ],
    [
        'method' => 'POST',
        'uri' => '/api/v1/stock-counts/{count}/unlock',
        'module' => 'shifts',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);
            $item = $f->ingredient();
            $f->stock($item, $f->place($f->kitchen), '100');
            $shift = $f->running($f->kitchen, $f->chef);
            $count = (int) $f->close($f->chef, $shift->id)->json('counts.0.id');
            $f->lines($f->chef, $count, ['lines' => [['item_id' => $item->id, 'counted_qty' => '90', 'reason_id' => $f->reasonId('spoilage')]]])->assertOk();
            $f->submit($f->chef, $count)->assertOk();
            $f->approve($f->admin, $count)->assertOk();
            loginAs($f->admin);

            return ['{count}' => (string) $count];
        },
        'payload' => fn (): array => ['reason' => 'Counted the wrong sack'],
    ],
    // Step 3.6: a handover note, written by the chef at the kitchen's running shift
    [
        'method' => 'POST',
        'uri' => '/api/v1/shifts/{shift}/notes',
        'module' => 'shifts',
        'table' => 'handover_notes',
        'setup' => function (TestCase $t) use ($shiftsFloor): array {
            $f = $shiftsFloor($t);

            return ['{shift}' => (string) $f->running($f->kitchen, $f->chef)->id];
        },
        'payload' => fn (): array => ['client_uuid' => (string) Str::uuid(), 'body' => 'The mixer needs oil'],
    ],
    // Files (step 4.2b): the one multipart write (the fingerprint is the fields plus the sha256 of the file) and the delete of an unattached file
    [
        'method' => 'POST',
        'uri' => '/api/v1/files',
        'module' => 'shared',
        'table' => 'stored_files',
        'setup' => function (TestCase $t) use ($admin): array {
            Storage::fake(config('files.disk'));
            $admin();

            return [];
        },
        'payload' => fn (): array => ['purpose' => 'receipt', 'client_uuid' => '7f0c9a3e-5c1d-4a52-9d57-0d3a6a1f2b10'],
        'files' => fn (): array => ['file' => UploadedFile::fake()->createWithContent('slip.jpg', imgJpeg(640, 480))],
    ],
    [
        'method' => 'DELETE',
        'uri' => '/api/v1/files/{file}',
        'module' => 'shared',
        'table' => 'audit_log',
        'setup' => function (TestCase $t) use ($admin): array {
            Storage::fake(config('files.disk'));
            $me = $admin();
            withActor($me->id);

            return ['{file}' => (string) app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid())->id];
        },
        'payload' => fn (): array => [],
    ],
];
