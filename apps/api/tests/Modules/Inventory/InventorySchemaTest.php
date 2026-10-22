<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Http\ApiError;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The Inventory tables as domain-model.md and ADR 0002 describe them, asked of the database itself (MySQL 8 locally,
 * MariaDB 10.11 in CI and live; portable to PostgreSQL, ADR 0006): DECIMAL(14,4) quantities in base units, branch_id
 * on every table, the append-only ledger with its triggers, the balance row unique per (item, location) that the
 * Ledger locks, and item_stock_view as a plain view over the movements for reconciliation.
 */

const INVENTORY_TABLES = ['locations', 'stock_movements', 'stock_balances'];

function inventoryColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** One raw movement row (the test writes around the Ledger on purpose: it probes the database's own guards). */
function rawMovement(array $over = []): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $item = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);
    $location = Location::factory()->create(['branch_id' => $branch->id]);

    return $over + [
        'branch_id' => $branch->id, 'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
        'qty' => '5.0000', 'reason' => 'purchase_in', 'ref_type' => 'probe', 'ref_id' => null, 'reservation_ref' => null,
        'shift_instance_id' => null, 'user_id' => $user->id, 'acting_user_id' => null, 'station_id' => null,
        'device_id' => null, 'client_uuid' => null, 'created_at' => now(),
    ];
}

it('creates the inventory tables, each with branch_id NOT NULL and a foreign key to branches', function () {
    foreach (INVENTORY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
        $column = inventoryColumn($table, 'branch_id');
        expect($column)->not->toBeNull("{$table}.branch_id")
            ->and(strtoupper((string) $column?->nullable))->toBe('NO', "{$table}.branch_id must be NOT NULL");

        $fk = DB::selectOne(
            "select 1 as ok from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = 'branch_id' and referenced_table_name = 'branches'",
            [dbSchema(), $table],
        );
        expect($fk)->not->toBeNull("{$table}.branch_id must reference branches");
    }
})->group('module:inventory', 'invariants');

it('carries the columns the ledger, the lock and the reconciliation rely on', function () {
    expect(Schema::hasColumns('locations', ['id', 'branch_id', 'name_en', 'name_am', 'station_id', 'type', 'created_at', 'updated_at', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('stock_movements', [
            'id', 'branch_id', 'item_id', 'from_location_id', 'to_location_id', 'qty', 'reason', 'ref_type', 'ref_id',
            'reservation_ref', 'shift_instance_id', 'user_id', 'acting_user_id', 'station_id', 'device_id', 'client_uuid', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('stock_balances', ['id', 'branch_id', 'item_id', 'location_id', 'on_hand', 'reserved', 'updated_at']))->toBeTrue();

    // the actor stamp of a movement: who (NOT NULL), acting-as, shift (FK to shift_instances since step 3.2a), station, device
    expect(strtoupper((string) inventoryColumn('stock_movements', 'user_id')?->nullable))->toBe('NO');
    foreach (['acting_user_id', 'shift_instance_id', 'station_id', 'device_id', 'client_uuid', 'reservation_ref', 'from_location_id', 'to_location_id'] as $nullable) {
        expect(strtoupper((string) inventoryColumn('stock_movements', $nullable)?->nullable))->toBe('YES', "stock_movements.{$nullable}");
    }
    $shiftFk = DB::selectOne(
        "select 1 as ok from information_schema.key_column_usage
          where table_schema = ? and table_name = 'stock_movements' and column_name = 'shift_instance_id' and referenced_table_name is not null",
        [dbSchema()],
    );
    expect($shiftFk)->not->toBeNull('shift_instance_id has its foreign key to shift_instances since step 3.2a (dependency-graph.md cycle 1)');
})->group('module:inventory', 'invariants');

it('stores every quantity as DECIMAL(14,4)', function () {
    foreach (['stock_movements.qty', 'stock_balances.on_hand', 'stock_balances.reserved'] as $path) {
        [$table, $name] = explode('.', $path);
        $column = inventoryColumn($table, $name);
        expect($column)->not->toBeNull($path)
            ->and(strtolower((string) $column?->data_type))->toBeIn(['decimal', 'numeric'], $path)
            ->and("{$column?->p},{$column?->s}")->toBe('14,4', $path);
    }
})->group('module:inventory', 'invariants');

it('pairs the bilingual location names and soft-deletes locations only', function () {
    expect(strtoupper((string) inventoryColumn('locations', 'name_am')?->nullable))->toBe('NO')
        ->and(strtoupper((string) inventoryColumn('locations', 'name_en')?->nullable))->toBe('NO')
        ->and(Schema::hasColumn('stock_movements', 'deleted_at'))->toBeFalse('the ledger is append-only')
        ->and(Schema::hasColumn('stock_movements', 'updated_at'))->toBeFalse('a movement is written once')
        ->and(Schema::hasColumn('stock_balances', 'deleted_at'))->toBeFalse('a balance is kept up to date, never deleted');
})->group('module:inventory', 'invariants');

it('constrains location types, movement reasons, a zero quantity and a movement without a place', function () {
    $branch = Branch::factory()->create();
    $location = fn (string $type): array => [
        'branch_id' => $branch->id, 'name_en' => 'Somewhere', 'name_am' => 'የሆነ ቦታ', 'type' => $type,
        'created_at' => now(), 'updated_at' => now(),
    ];
    foreach (LocationType::values() as $type) {
        DB::table('locations')->insert($location($type));
    }
    expect(LocationType::values())->toBe(['store', 'kitchen', 'bar', 'cake_room', 'bakery', 'display_case', 'cash_drawer'])
        ->and(fn () => DB::table('locations')->insert($location('freezer')))->toThrow(QueryException::class);

    DB::table('stock_movements')->insert(rawMovement());
    expect(fn () => DB::table('stock_movements')->insert(rawMovement(['reason' => 'theft'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('stock_movements')->insert(rawMovement(['qty' => '0'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('stock_movements')->insert(rawMovement(['to_location_id' => null])))->toThrow(QueryException::class);
})->group('module:inventory', 'invariants');

it('keeps client_uuid unique per branch and lets two branches reuse one', function () {
    $uuid = (string) Str::uuid();
    $first = rawMovement(['client_uuid' => $uuid]);
    DB::table('stock_movements')->insert($first);
    DB::table('stock_movements')->insert(rawMovement(['client_uuid' => $uuid])); // another branch

    expect(fn () => DB::table('stock_movements')->insert(['client_uuid' => $uuid] + $first))->toThrow(QueryException::class);
})->group('module:inventory', 'invariants');

it('keeps one balance row per item and location, never negative', function () {
    $movement = rawMovement();
    $row = [
        'branch_id' => $movement['branch_id'], 'item_id' => $movement['item_id'], 'location_id' => $movement['to_location_id'],
        'on_hand' => '1.0000', 'reserved' => '0.0000', 'updated_at' => now(),
    ];
    DB::table('stock_balances')->insert($row);

    expect(fn () => DB::table('stock_balances')->insert($row))->toThrow(QueryException::class)
        ->and(fn () => DB::table('stock_balances')->where('item_id', $row['item_id'])->update(['on_hand' => '-0.0001']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('stock_balances')->where('item_id', $row['item_id'])->update(['reserved' => '-1']))->toThrow(QueryException::class);
})->group('module:inventory', 'invariants');

it('refuses UPDATE and DELETE on stock_movements through triggers named stock_movements_forbid_update_delete_*', function () {
    $id = DB::table('stock_movements')->insertGetId(rawMovement());

    $triggers = array_map(fn ($t) => (string) $t->name, DB::select(
        'select trigger_name as name from information_schema.triggers where trigger_schema = ? and event_object_table = ?',
        [dbSchema(), 'stock_movements'],
    ));
    expect($triggers)->not->toBeEmpty();
    foreach ($triggers as $name) {
        expect($name)->toStartWith('stock_movements_forbid_update_delete');
    }

    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->where('id', $id)->update(['qty' => '6'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_movements')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('stock_movements')->where('id', $id)->value('qty'))->toBe('5.0000');
})->group('module:inventory', 'invariants');

it('defines item_stock_view as a plain view over the movements, by item and location', function () {
    $view = DB::selectOne(
        "select table_type as type from information_schema.tables where table_schema = ? and table_name = 'item_stock_view'",
        [dbSchema()],
    );
    expect($view)->not->toBeNull('item_stock_view is missing')
        ->and(strtoupper((string) $view?->type))->toBe('VIEW');

    $columns = array_map(fn ($c) => (string) $c->name, DB::select(
        "select column_name as name from information_schema.columns where table_schema = ? and table_name = 'item_stock_view'",
        [dbSchema()],
    ));
    expect($columns)->toContain('branch_id', 'item_id', 'location_id', 'on_hand', 'reserved', 'available');
})->group('module:inventory', 'invariants');

it('keeps the en and am inventory dictionaries in step, and ApiError reads them', function () {
    $en = Arr::dot(require base_path('lang/en/inventory.php'));
    $am = Arr::dot(require base_path('lang/am/inventory.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en))->and($en)->not->toBeEmpty();
    foreach ($en as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty("en {$key} is empty")
            ->and($am[$key])->toBeString()->not->toBeEmpty("am {$key} is empty")->not->toBe($value, "am {$key} is still English");
    }

    $body = ApiError::body('inventory.stock.insufficient');
    expect($body['message_en'])->toBe($en['stock.insufficient'])->and($body['message_am'])->toBe($am['stock.insufficient']);
})->group('module:inventory', 'invariants');
