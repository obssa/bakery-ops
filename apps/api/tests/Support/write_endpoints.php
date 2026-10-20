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
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\OrdersMenu;
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
];
