<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Enums\RejectReason;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The Orders tables exactly as docs/phases/phase-1-plan.md section 3 (migrations 1-6) states them, asked of the
 * database itself (MariaDB 10.11 locally, in CI and live; portable to PostgreSQL, ADR 0006): the columns, money
 * DECIMAL(12,2) and quantities DECIMAL(14,4), branch_id on every table, the bilingual snapshots, the actor stamp
 * (shift_instance_id with its foreign key to shift_instances since step 3.2a), client_uuid unique per branch, the indexes the pad, KDS
 * and till feeds read, the CHECK lists that mirror the enums, soft deletes on dining_tables only, and order_events
 * append-only through triggers named order_events_forbid_update_delete_*. Rows are written raw on purpose: the test
 * probes the database's own guards, not the models.
 */

const ORDERS_TABLES = ['dining_tables', 'order_counters', 'orders', 'order_lines', 'order_line_modifiers', 'order_events'];

const ORDERS_COLUMNS = [
    'dining_tables' => [
        'id', 'branch_id', 'code', 'name_en', 'name_am', 'seats', 'sort', 'is_active', 'created_by_user_id',
        'created_at', 'updated_at', 'deleted_at',
    ],
    'order_counters' => ['id', 'branch_id', 'business_date', 'last_no', 'updated_at'],
    'orders' => [
        'id', 'branch_id', 'client_uuid', 'order_no', 'business_date', 'type', 'dining_table_id', 'table_code', 'status',
        'created_via', 'owner_user_id', 'created_by_user_id', 'acting_user_id', 'shift_instance_id', 'station_id',
        'device_id', 'subtotal', 'discount', 'tax', 'total', 'paid_total', 'deposit_amount', 'currency', 'notes', 'sent_at', 'settled_at',
        'paid_at', 'closed_at', 'cancelled_at', 'cancel_reason', 'voided_at', 'void_reason', 'voided_by_user_id',
        'void_approved_by_user_id', 'due_at', 'version', 'created_at', 'updated_at',
        // step 4.1b (phase-4 plan M8): what came back of the money (an order stays closed after a refund)
        'refunded_total', 'refunded_at',
    ],
    'order_lines' => [
        'id', 'branch_id', 'order_id', 'client_uuid', 'item_id', 'qty', 'unit_price_snapshot', 'modifier_price_snapshot',
        'line_total', 'recipe_version', 'station_id', 'location_id', 'reservation_ref', 'status', 'notes',
        'accepted_by_user_id', 'accepted_at', 'prepared_by_user_id', 'ready_at', 'served_by_user_id', 'served_at',
        'rejected_by_user_id', 'rejected_at', 'reject_reason', 'reject_note', 'created_by_user_id', 'acting_user_id',
        'shift_instance_id', 'device_id', 'version', 'created_at', 'updated_at',
        // step 4.1b (phase-4 plan M8): a line is refunded once
        'refunded_at',
    ],
    'order_line_modifiers' => [
        'id', 'branch_id', 'order_line_id', 'modifier_id', 'name_en', 'name_am', 'price_delta_snapshot',
        'recipe_delta_snapshot', 'created_at',
    ],
    'order_events' => [
        'id', 'branch_id', 'order_id', 'order_line_id', 'station_id', 'owner_user_id', 'event', 'user_id',
        'acting_user_id', 'approved_by_user_id', 'actor_station_id', 'device_id', 'shift_instance_id', 'payload',
        'created_at',
    ],
];

function ordersColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable,
                datetime_precision as dp
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return list<string> */
function ordersColumnNames(string $table): array
{
    return array_map(fn ($c) => (string) $c->name, DB::select(
        'select column_name as name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
        [dbSchema(), $table],
    ));
}

/** The table a column's foreign key points at, or null when it has none (MySQL / MariaDB information_schema). */
function ordersReferencedTable(string $table, string $column): ?string
{
    $row = DB::selectOne(
        'select referenced_table_name as ref from information_schema.key_column_usage
          where table_schema = ? and table_name = ? and column_name = ? and referenced_table_name is not null',
        [dbSchema(), $table, $column],
    );

    return $row === null ? null : (string) $row->ref;
}

/**
 * The indexes of a table as comma-joined column lists, split into unique and plain (primary key left out).
 *
 * @return array{unique: list<string>, plain: list<string>}
 */
function ordersIndexes(string $table): array
{
    $out = ['unique' => [], 'plain' => []];
    if (DB::connection()->getDriverName() === 'pgsql') {
        foreach (DB::select("select indexname as name, indexdef as def from pg_indexes where schemaname = 'public' and tablename = ?", [$table]) as $row) {
            if (str_ends_with((string) $row->name, '_pkey')) {
                continue;
            }
            preg_match('/\((.*)\)/', (string) $row->def, $m);
            $out[str_contains((string) $row->def, 'UNIQUE') ? 'unique' : 'plain'][] = str_replace([' ', '"'], '', $m[1] ?? '');
        }

        return $out;
    }

    $rows = DB::select(
        "select index_name as name, max(non_unique) as non_unique, group_concat(column_name order by seq_in_index) as cols
           from information_schema.statistics
          where table_schema = ? and table_name = ? and index_name <> 'PRIMARY'
          group by index_name",
        [dbSchema(), $table],
    );
    foreach ($rows as $row) {
        $out[(int) $row->non_unique === 0 ? 'unique' : 'plain'][] = (string) $row->cols;
    }

    return $out;
}

/**
 * The parents an order needs, in one fresh branch: an actor, a waiter (the owner), a kitchen station with its
 * location, a to-order product with one modifier, a device.
 *
 * @return array{branch: Branch, user: User, waiter: User, station: Station, location: Location, item: Item, modifier: Modifier, device: Device}
 */
function ordersParents(): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
    $item = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);

    return [
        'branch' => $branch,
        'user' => $user,
        'waiter' => User::factory()->create(['branch_id' => $branch->id]),
        'station' => $station,
        'location' => Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $branch->id, 'station_id' => $station->id]),
        'item' => $item,
        'modifier' => Modifier::factory()->create(['branch_id' => $branch->id, 'item_id' => $item->id]),
        'device' => Device::factory()->create(['branch_id' => $branch->id]),
    ];
}

/**
 * @param  array<string, mixed>  $p  ordersParents()
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawOrder(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'order_no' => null, 'business_date' => null,
        'type' => 'dine_in', 'dining_table_id' => null, 'table_code' => null, 'status' => 'open', 'created_via' => 'waiter_pad',
        'owner_user_id' => $p['waiter']->id, 'created_by_user_id' => $p['user']->id, 'acting_user_id' => $p['waiter']->id,
        'shift_instance_id' => null, 'station_id' => null, 'device_id' => $p['device']->id,
        'subtotal' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'paid_total' => '0.00', 'currency' => 'ETB',
        'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

/**
 * @param  array<string, mixed>  $p  ordersParents()
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawLine(array $p, int $orderId, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'order_id' => $orderId, 'client_uuid' => (string) Str::uuid(), 'item_id' => $p['item']->id,
        'qty' => '2.0000', 'unit_price_snapshot' => '120.00', 'modifier_price_snapshot' => '15.00', 'line_total' => '270.00',
        'recipe_version' => 1, 'station_id' => $p['station']->id, 'location_id' => $p['location']->id,
        'reservation_ref' => (string) Str::uuid(), 'status' => 'queued', 'created_by_user_id' => $p['user']->id,
        'acting_user_id' => $p['waiter']->id, 'device_id' => $p['device']->id, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

/**
 * @param  array<string, mixed>  $p  ordersParents()
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawOrderEvent(array $p, int $orderId, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'order_id' => $orderId, 'order_line_id' => null, 'station_id' => null,
        'owner_user_id' => $p['waiter']->id, 'event' => 'order.created', 'user_id' => $p['user']->id,
        'acting_user_id' => $p['waiter']->id, 'approved_by_user_id' => null, 'actor_station_id' => null,
        'device_id' => $p['device']->id, 'shift_instance_id' => null, 'payload' => json_encode(['lines' => 1]),
        'created_at' => now(),
    ];
}

it('creates exactly the columns of the plan on every Orders table', function () {
    foreach (ORDERS_COLUMNS as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}")
            ->and(ordersColumnNames($table))->toEqualCanonicalizing($columns, "{$table} columns");
    }
})->group('module:orders', 'invariants');

it('gives every Orders table branch_id NOT NULL with a foreign key to branches and an index', function () {
    foreach (ORDERS_TABLES as $table) {
        expect(strtoupper((string) ordersColumn($table, 'branch_id')?->nullable))->toBe('NO', "{$table}.branch_id")
            ->and(ordersReferencedTable($table, 'branch_id'))->toBe('branches', "{$table}.branch_id must reference branches");

        $indexed = array_filter(
            array_merge(...array_values(ordersIndexes($table))),
            fn (string $cols): bool => str_starts_with($cols, 'branch_id'),
        );
        expect($indexed)->not->toBeEmpty("{$table}.branch_id must lead an index");
    }
})->group('module:orders', 'invariants');

it('stores money as DECIMAL(12,2) and line quantities as DECIMAL(14,4) in units of the product', function () {
    $expected = [
        'orders.subtotal' => '12,2', 'orders.discount' => '12,2', 'orders.tax' => '12,2', 'orders.total' => '12,2',
        'orders.paid_total' => '12,2', 'orders.deposit_amount' => '12,2', 'orders.refunded_total' => '12,2', 'order_lines.qty' => '14,4', 'order_lines.unit_price_snapshot' => '12,2',
        'order_lines.modifier_price_snapshot' => '12,2', 'order_lines.line_total' => '12,2',
        'order_line_modifiers.price_delta_snapshot' => '12,2',
    ];
    foreach ($expected as $path => $scale) {
        [$table, $name] = explode('.', $path);
        $column = ordersColumn($table, $name);
        expect($column)->not->toBeNull($path)
            ->and(strtolower((string) $column?->data_type))->toBeIn(['decimal', 'numeric'], $path)
            ->and("{$column?->p},{$column?->s}")->toBe($scale, $path)
            ->and(strtoupper((string) $column?->nullable))->toBe('NO', "{$path} is never null");
    }
})->group('module:orders', 'invariants');

it('pairs the bilingual names of dining tables and of the modifier snapshot, both required', function () {
    foreach (['dining_tables', 'order_line_modifiers'] as $table) {
        foreach (['name_en', 'name_am'] as $column) {
            expect(strtoupper((string) ordersColumn($table, $column)?->nullable))->toBe('NO', "{$table}.{$column}");
        }
    }
})->group('module:orders', 'invariants');

it('stamps the actor on orders, lines and events: who NOT NULL, acting-as, station and device with foreign keys, shift without one until Phase 3', function () {
    $stamps = [
        'orders' => ['who' => ['created_by_user_id' => 'users', 'owner_user_id' => 'users'], 'optional' => ['acting_user_id' => 'users', 'station_id' => 'stations', 'device_id' => 'devices', 'voided_by_user_id' => 'users', 'void_approved_by_user_id' => 'users']],
        'order_lines' => ['who' => ['created_by_user_id' => 'users'], 'optional' => ['acting_user_id' => 'users', 'device_id' => 'devices', 'accepted_by_user_id' => 'users', 'prepared_by_user_id' => 'users', 'served_by_user_id' => 'users', 'rejected_by_user_id' => 'users']],
        'order_events' => ['who' => ['user_id' => 'users', 'owner_user_id' => 'users'], 'optional' => ['acting_user_id' => 'users', 'approved_by_user_id' => 'users', 'actor_station_id' => 'stations', 'device_id' => 'devices']],
        'dining_tables' => ['who' => ['created_by_user_id' => 'users'], 'optional' => []],
    ];
    foreach ($stamps as $table => $columns) {
        foreach ($columns['who'] as $column => $references) {
            expect(strtoupper((string) ordersColumn($table, $column)?->nullable))->toBe('NO', "{$table}.{$column}")
                ->and(ordersReferencedTable($table, $column))->toBe($references, "{$table}.{$column}");
        }
        foreach ($columns['optional'] as $column => $references) {
            expect(strtoupper((string) ordersColumn($table, $column)?->nullable))->toBe('YES', "{$table}.{$column}")
                ->and(ordersReferencedTable($table, $column))->toBe($references, "{$table}.{$column}");
        }
    }
    foreach (['orders', 'order_lines', 'order_events'] as $table) {
        expect(strtoupper((string) ordersColumn($table, 'shift_instance_id')?->nullable))->toBe('YES', "{$table}.shift_instance_id")
            ->and(ordersReferencedTable($table, 'shift_instance_id'))->toBe('shift_instances', "{$table}.shift_instance_id has its foreign key to shift_instances since step 3.2a");
    }
})->group('module:orders', 'invariants');

it('links the tables: lines to their order, item, station and stock location; modifiers to their line; events to their order, line and station', function () {
    $links = [
        'orders.dining_table_id' => 'dining_tables', 'order_lines.order_id' => 'orders', 'order_lines.item_id' => 'items',
        'order_lines.station_id' => 'stations', 'order_lines.location_id' => 'locations',
        'order_line_modifiers.order_line_id' => 'order_lines', 'order_line_modifiers.modifier_id' => 'modifiers',
        'order_events.order_id' => 'orders', 'order_events.order_line_id' => 'order_lines', 'order_events.station_id' => 'stations',
    ];
    foreach ($links as $path => $references) {
        [$table, $column] = explode('.', $path);
        expect(ordersReferencedTable($table, $column))->toBe($references, $path);
    }
    foreach (['orders.dining_table_id', 'order_lines.station_id', 'order_lines.recipe_version', 'order_events.order_line_id', 'order_events.station_id'] as $path) {
        [$table, $column] = explode('.', $path);
        expect(strtoupper((string) ordersColumn($table, $column)?->nullable))->toBe('YES', $path);
    }
    foreach (['order_lines.location_id', 'order_lines.reservation_ref', 'order_lines.client_uuid', 'orders.client_uuid'] as $path) {
        [$table, $column] = explode('.', $path);
        expect(strtoupper((string) ordersColumn($table, $column)?->nullable))->toBe('NO', $path);
    }
})->group('module:orders', 'invariants');

it('has the unique keys and the indexes the pad, KDS and till feeds read', function () {
    $expected = [
        'dining_tables' => ['unique' => ['branch_id,code'], 'plain' => []],
        'order_counters' => ['unique' => ['branch_id,business_date'], 'plain' => []],
        'orders' => [
            'unique' => ['branch_id,client_uuid', 'branch_id,business_date,order_no'],
            'plain' => ['branch_id,status', 'branch_id,owner_user_id,status', 'dining_table_id,status'],
        ],
        'order_lines' => ['unique' => ['branch_id,client_uuid', 'reservation_ref'], 'plain' => ['order_id', 'branch_id,station_id,status']],
        'order_events' => ['unique' => [], 'plain' => ['branch_id,station_id,id', 'branch_id,owner_user_id,id', 'order_id,id']],
    ];
    foreach ($expected as $table => $keys) {
        $indexes = ordersIndexes($table);
        foreach ($keys['unique'] as $columns) {
            expect(in_array($columns, $indexes['unique'], true))->toBeTrue("{$table}: unique ({$columns}) missing, has: ".implode(' | ', $indexes['unique']));
        }
        foreach ($keys['plain'] as $columns) {
            expect(in_array($columns, array_merge($indexes['plain'], $indexes['unique']), true))->toBeTrue("{$table}: index ({$columns}) missing, has: ".implode(' | ', $indexes['plain']));
        }
    }
})->group('module:orders', 'invariants');

it('keeps client_uuid unique per branch on orders and lines, lets two branches reuse one, and keeps reservation_ref unique', function () {
    $p = ordersParents();
    $other = ordersParents();
    $uuid = (string) Str::uuid();

    $orderId = DB::table('orders')->insertGetId(rawOrder($p, ['client_uuid' => $uuid]));
    $otherOrderId = DB::table('orders')->insertGetId(rawOrder($other, ['client_uuid' => $uuid]));
    expect(fn () => DB::table('orders')->insert(rawOrder($p, ['client_uuid' => $uuid])))->toThrow(QueryException::class);

    $ref = (string) Str::uuid();
    DB::table('order_lines')->insert(rawLine($p, $orderId, ['client_uuid' => $uuid, 'reservation_ref' => $ref]));
    DB::table('order_lines')->insert(rawLine($other, $otherOrderId, ['client_uuid' => $uuid]));
    expect(fn () => DB::table('order_lines')->insert(rawLine($p, $orderId, ['client_uuid' => $uuid])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_lines')->insert(rawLine($p, $orderId, ['reservation_ref' => $ref])))->toThrow(QueryException::class)
        ->and(DB::table('order_lines')->count())->toBe(2);
})->group('module:orders', 'invariants');

it('numbers orders once per branch and business day, leaves an open order unnumbered, and keeps one counter row per day', function () {
    $p = ordersParents();
    $other = ordersParents();
    $day = '2026-10-21';

    DB::table('orders')->insert(rawOrder($p));
    DB::table('orders')->insert(rawOrder($p)); // two open carts: no number, no business day yet
    DB::table('orders')->insert(rawOrder($p, ['status' => 'sent', 'order_no' => 1, 'business_date' => $day, 'sent_at' => now()]));
    DB::table('orders')->insert(rawOrder($p, ['status' => 'sent', 'order_no' => 1, 'business_date' => '2026-10-22', 'sent_at' => now()]));
    DB::table('orders')->insert(rawOrder($other, ['status' => 'sent', 'order_no' => 1, 'business_date' => $day, 'sent_at' => now()]));

    expect(fn () => DB::table('orders')->insert(rawOrder($p, ['status' => 'sent', 'order_no' => 1, 'business_date' => $day])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->insert(rawOrder($p, ['order_no' => 2])))->toThrow(QueryException::class, 'orders_numbered_on_a_business_date')
        ->and(fn () => DB::table('orders')->insert(rawOrder($p, ['business_date' => $day])))->toThrow(QueryException::class, 'orders_numbered_on_a_business_date');

    DB::table('order_counters')->insert(['branch_id' => $p['branch']->id, 'business_date' => $day, 'last_no' => 1, 'updated_at' => now()]);
    DB::table('order_counters')->insert(['branch_id' => $other['branch']->id, 'business_date' => $day, 'last_no' => 1, 'updated_at' => now()]);
    expect(fn () => DB::table('order_counters')->insert(['branch_id' => $p['branch']->id, 'business_date' => $day, 'last_no' => 2, 'updated_at' => now()]))
        ->toThrow(QueryException::class)
        ->and(DB::table('order_counters')->where('branch_id', $p['branch']->id)->value('last_no'))->toBe(1);
})->group('module:orders', 'invariants');

it('keeps dining table codes unique per branch and soft-deletes dining tables only', function () {
    $p = ordersParents();
    $other = ordersParents();
    $table = fn (array $parents): array => [
        'branch_id' => $parents['branch']->id, 'code' => 'T1', 'name_en' => 'Table 1', 'name_am' => 'ጠረጴዛ 1', 'seats' => 4,
        'sort' => 1, 'is_active' => true, 'created_by_user_id' => $parents['user']->id, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('dining_tables')->insert($table($p));
    DB::table('dining_tables')->insert($table($other));

    expect(fn () => DB::table('dining_tables')->insert($table($p)))->toThrow(QueryException::class)
        ->and(Schema::hasColumn('dining_tables', 'deleted_at'))->toBeTrue();
    foreach (['order_counters', 'orders', 'order_lines', 'order_line_modifiers', 'order_events'] as $never) {
        expect(Schema::hasColumn($never, 'deleted_at'))->toBeFalse("{$never} is never deleted (cancelled / voided by status)");
    }
})->group('module:orders', 'invariants');

it('constrains order type, status and channel, line status and reject reason, and event names to the enums', function () {
    $p = ordersParents();

    expect(OrderType::values())->toBe(['dine_in', 'takeaway', 'delivery', 'advance'])
        ->and(CreatedVia::values())->toBe(['waiter_pad', 'till', 'admin'])
        ->and(RejectReason::values())->toBe(['ingredient_spoiled', 'equipment_down', 'out_of_stock', 'other'])
        ->and(OrderEventType::values())->toBe([
            'order.created', 'order.lines_replaced', 'order.sent', 'order.send_refused', 'line.queued', 'line.accepted',
            'line.ready', 'line.rejected', 'line.served', 'line.voided', 'order.in_progress', 'order.ready', 'order.served',
            'order.settled', 'order.paid', 'order.closed', 'order.cancelled', 'order.voided',
            // step 4.3c (phase-4 plan M9, the payment half): the payments on the order's timeline
            'payment.recorded', 'payment.voided',
            // step 4.1b (the refund half of M9, its own migration): a refund on the order's timeline
            'line.refunded', 'order.refunded',
        ]);

    foreach (OrderType::values() as $type) {
        DB::table('orders')->insert(rawOrder($p, ['type' => $type]));
    }
    foreach (OrderStatus::values() as $status) {
        DB::table('orders')->insert(rawOrder($p, ['status' => $status]));
    }
    foreach (CreatedVia::values() as $via) {
        DB::table('orders')->insert(rawOrder($p, ['created_via' => $via]));
    }
    expect(fn () => DB::table('orders')->insert(rawOrder($p, ['type' => 'catering'])))->toThrow(QueryException::class, 'orders_type_check')
        ->and(fn () => DB::table('orders')->insert(rawOrder($p, ['status' => 'lost'])))->toThrow(QueryException::class, 'orders_status_check')
        ->and(fn () => DB::table('orders')->insert(rawOrder($p, ['created_via' => 'phone'])))->toThrow(QueryException::class, 'orders_created_via_check');

    $orderId = DB::table('orders')->insertGetId(rawOrder($p));
    foreach (LineStatus::values() as $status) {
        DB::table('order_lines')->insert(rawLine($p, $orderId, ['status' => $status]));
    }
    foreach (RejectReason::values() as $reason) {
        DB::table('order_lines')->insert(rawLine($p, $orderId, ['status' => 'rejected', 'reject_reason' => $reason]));
    }
    expect(fn () => DB::table('order_lines')->insert(rawLine($p, $orderId, ['status' => 'cooking'])))->toThrow(QueryException::class, 'order_lines_status_check')
        ->and(fn () => DB::table('order_lines')->insert(rawLine($p, $orderId, ['reject_reason' => 'tired'])))->toThrow(QueryException::class, 'order_lines_reject_reason_check')
        ->and(fn () => DB::table('order_lines')->insert(rawLine($p, $orderId, ['qty' => '0'])))->toThrow(QueryException::class, 'order_lines_qty_positive')
        ->and(fn () => DB::table('order_lines')->insert(rawLine($p, $orderId, ['qty' => '-1.0000'])))->toThrow(QueryException::class, 'order_lines_qty_positive');

    foreach (OrderEventType::values() as $event) {
        DB::table('order_events')->insert(rawOrderEvent($p, $orderId, ['event' => $event]));
    }
    expect(fn () => DB::table('order_events')->insert(rawOrderEvent($p, $orderId, ['event' => 'order.reprinted'])))->toThrow(QueryException::class, 'order_events_event_check')
        ->and(DB::table('order_events')->count())->toBe(count(OrderEventType::values()));
})->group('module:orders', 'invariants');

it('defaults a new order to open with zero money and version 1, and a new line to queued with no modifier price', function () {
    $p = ordersParents();
    $order = rawOrder($p);
    unset($order['status'], $order['subtotal'], $order['discount'], $order['tax'], $order['total'], $order['paid_total'], $order['version']);
    $orderId = DB::table('orders')->insertGetId($order);

    $line = rawLine($p, $orderId);
    unset($line['status'], $line['modifier_price_snapshot'], $line['version']);
    $lineId = DB::table('order_lines')->insertGetId($line);

    $row = DB::table('orders')->find($orderId);
    expect($row?->status)->toBe('open')
        ->and([$row?->subtotal, $row?->discount, $row?->tax, $row?->total, $row?->paid_total])->toBe(['0.00', '0.00', '0.00', '0.00', '0.00'])
        ->and((int) $row?->version)->toBe(1);
    $lineRow = DB::table('order_lines')->find($lineId);
    expect($lineRow?->status)->toBe('queued')
        ->and($lineRow?->modifier_price_snapshot)->toBe('0.00')
        ->and((int) $lineRow?->version)->toBe(1);
})->group('module:orders', 'invariants');

it('writes a modifier snapshot once, with its created_at and no updated_at, and keeps a counter row updated_at only', function () {
    $p = ordersParents();
    $orderId = DB::table('orders')->insertGetId(rawOrder($p));
    $lineId = DB::table('order_lines')->insertGetId(rawLine($p, $orderId));
    DB::table('order_line_modifiers')->insert([
        'branch_id' => $p['branch']->id, 'order_line_id' => $lineId, 'modifier_id' => $p['modifier']->id,
        'name_en' => 'Extra cheese', 'name_am' => 'ተጨማሪ አይብ', 'price_delta_snapshot' => '15.00',
        'recipe_delta_snapshot' => json_encode([['ingredient_item_id' => $p['item']->id, 'qty' => '20.0000', 'unit' => 'g']]),
        'created_at' => now(),
    ]);

    expect(DB::table('order_line_modifiers')->where('order_line_id', $lineId)->value('price_delta_snapshot'))->toBe('15.00')
        ->and(Schema::hasColumn('order_line_modifiers', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('order_counters', 'created_at'))->toBeFalse();
})->group('module:orders', 'invariants');

it('keeps order_events append-only through triggers named order_events_forbid_update_delete_*, with microsecond created_at and no updated_at', function () {
    $p = ordersParents();
    $orderId = DB::table('orders')->insertGetId(rawOrder($p));
    $id = DB::table('order_events')->insertGetId(rawOrderEvent($p, $orderId));

    $triggers = array_map(fn ($t) => (string) $t->name, DB::select(
        'select trigger_name as name from information_schema.triggers where trigger_schema = ? and event_object_table = ?',
        [dbSchema(), 'order_events'],
    ));
    expect($triggers)->not->toBeEmpty();
    foreach ($triggers as $name) {
        expect($name)->toStartWith('order_events_forbid_update_delete');
    }

    expect(fn () => DB::transaction(fn () => DB::table('order_events')->where('id', $id)->update(['event' => 'order.voided'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('order_events')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('order_events')->where('id', $id)->value('event'))->toBe('order.created')
        ->and((int) ordersColumn('order_events', 'created_at')?->dp)->toBe(6)
        ->and(Schema::hasColumn('order_events', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('order_events', 'deleted_at'))->toBeFalse();
})->group('module:orders', 'invariants');
