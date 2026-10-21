<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Step 2.2a (phase-2 plan section 3, migrations 3-7): suppliers, purchases, purchase_lines, item_costs and the
 * append-only item_cost_history, asked of the database itself. Cost rates are DECIMAL(14,4) (a rate per base unit,
 * invariants version 2), totals of money DECIMAL(12,2), quantities DECIMAL(14,4); branch_id NOT NULL with a foreign key
 * on every table; the unique keys that make a replay find the same row; the CHECKs behind the state machine.
 */

const PURCHASE_TABLES = ['suppliers', 'purchases', 'purchase_lines', 'item_costs', 'item_cost_history'];

function purchaseColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return array{branch: Branch, user: User, supplier: int, location: int, item: int} the rows a purchase hangs on */
function purchaseParents(): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $supplier = DB::table('suppliers')->insertGetId([
        'branch_id' => $branch->id, 'name' => 'Addis Flour Mill', 'is_active' => true, 'created_by_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'branch' => $branch, 'user' => $user, 'supplier' => $supplier,
        'location' => Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $branch->id])->id,
        'item' => Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id])->id,
    ];
}

/** @return array<string, mixed> */
function rawPurchase(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'supplier_id' => $p['supplier'], 'invoice_no' => 'INV-1',
        'purchase_date' => '2026-10-21', 'location_id' => $p['location'], 'status' => 'draft', 'currency' => 'ETB', 'total' => '0.00',
        'notes' => null, 'confirmed_at' => null, 'confirmed_by_user_id' => null, 'cancelled_at' => null, 'cancel_reason' => null,
        'created_by_user_id' => $p['user']->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

/** @return array<string, mixed> */
function rawPurchaseLine(array $p, int $purchaseId, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'purchase_id' => $purchaseId, 'client_uuid' => (string) Str::uuid(), 'item_id' => $p['item'],
        'qty' => '2.0000', 'unit_id' => catalogUnit('kg')->id, 'qty_base' => '2000.0000', 'unit_cost' => '85.0000', 'unit_cost_base' => '0.0850',
        'line_total' => '170.00', 'movement_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ];
}

it('creates the five tables, each with branch_id NOT NULL and a foreign key to branches', function () {
    foreach (PURCHASE_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
        $column = purchaseColumn($table, 'branch_id');
        expect($column)->not->toBeNull("{$table}.branch_id")
            ->and(strtoupper((string) $column?->nullable))->toBe('NO', "{$table}.branch_id must be NOT NULL");

        $fk = DB::selectOne(
            "select 1 as ok from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = 'branch_id' and referenced_table_name = 'branches'",
            [dbSchema(), $table],
        );
        expect($fk)->not->toBeNull("{$table}.branch_id must reference branches");
    }
})->group('module:inventory', 'feature');

it('stores cost rates as DECIMAL(14,4), totals of money as DECIMAL(12,2) and quantities as DECIMAL(14,4)', function () {
    $expect = [
        ['purchase_lines', 'unit_cost', 14, 4], ['purchase_lines', 'unit_cost_base', 14, 4], ['purchase_lines', 'line_total', 12, 2],
        ['purchase_lines', 'qty', 14, 4], ['purchase_lines', 'qty_base', 14, 4],
        ['purchases', 'total', 12, 2],
        ['item_costs', 'moving_avg_cost', 14, 4], ['item_costs', 'qty_basis', 14, 4],
        ['item_cost_history', 'qty_before', 14, 4], ['item_cost_history', 'cost_before', 14, 4], ['item_cost_history', 'qty_in', 14, 4],
        ['item_cost_history', 'unit_cost_in', 14, 4], ['item_cost_history', 'cost_after', 14, 4],
    ];
    foreach ($expect as [$table, $column, $p, $s]) {
        $c = purchaseColumn($table, $column);
        expect($c)->not->toBeNull("{$table}.{$column}")
            ->and([(int) $c?->p, (int) $c?->s])->toBe([$p, $s], "{$table}.{$column}");
    }
    expect(strtoupper((string) purchaseColumn('item_cost_history', 'cost_before')?->nullable))->toBe('YES', 'null when the stock was unvalued')
        ->and(strtoupper((string) purchaseColumn('purchases', 'confirmed_at')?->nullable))->toBe('YES');
})->group('module:inventory', 'feature');

it('soft-deletes suppliers and purchase lines, never purchases, costs or history; and stamps who did it', function () {
    expect(Schema::hasColumn('suppliers', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('purchase_lines', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('purchases', 'deleted_at'))->toBeFalse('a purchase is cancelled by status')
        ->and(Schema::hasColumn('item_costs', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('item_cost_history', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumns('suppliers', ['created_by_user_id', 'acting_user_id', 'shift_instance_id', 'actor_station_id', 'device_id']))->toBeTrue()
        ->and(Schema::hasColumns('purchases', ['created_by_user_id', 'acting_user_id', 'shift_instance_id', 'actor_station_id', 'device_id', 'confirmed_by_user_id', 'version']))->toBeTrue()
        ->and(Schema::hasColumns('item_cost_history', ['user_id', 'acting_user_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('suppliers', 'name_en'))->toBeFalse('a supplier name is a proper noun: no bilingual pair');
})->group('module:inventory', 'feature');

it('keeps a client_uuid once per branch on purchases and on purchase lines', function () {
    $p = purchaseParents();
    $uuid = (string) Str::uuid();
    $purchaseId = DB::table('purchases')->insertGetId(rawPurchase($p, ['client_uuid' => $uuid]));

    expect(fn () => DB::table('purchases')->insert(rawPurchase($p, ['client_uuid' => $uuid])))->toThrow(QueryException::class);

    $lineUuid = (string) Str::uuid();
    DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['client_uuid' => $lineUuid]));
    expect(fn () => DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['client_uuid' => $lineUuid])))->toThrow(QueryException::class);

    // another branch may use the same client uuid
    $other = purchaseParents();
    DB::table('purchases')->insert(rawPurchase($other, ['client_uuid' => $uuid]));
    expect(DB::table('purchases')->where('client_uuid', $uuid)->count())->toBe(2);
})->group('module:inventory', 'feature');

it('keeps the status, the total and the confirmation to their rules', function () {
    $p = purchaseParents();

    expect(fn () => DB::table('purchases')->insert(rawPurchase($p, ['status' => 'received'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('purchases')->insert(rawPurchase($p, ['total' => '-0.01'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('purchases')->insert(rawPurchase($p, ['status' => 'confirmed', 'confirmed_at' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('purchases')->insert(rawPurchase($p, ['status' => 'draft', 'confirmed_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('purchases')->insert(rawPurchase($p, ['status' => 'cancelled', 'confirmed_at' => now()])))->toThrow(QueryException::class);

    DB::table('purchases')->insert(rawPurchase($p, ['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $p['user']->id, 'total' => '170.00']));
    DB::table('purchases')->insert(rawPurchase($p, ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'wrong supplier']));
    DB::table('purchases')->insert(rawPurchase($p));
    expect(DB::table('purchases')->count())->toBe(3);
})->group('module:inventory', 'feature');

it('keeps a purchase line to a positive quantity and a cost that is not negative, a zero cost allowed', function () {
    $p = purchaseParents();
    $purchaseId = DB::table('purchases')->insertGetId(rawPurchase($p));

    expect(fn () => DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['qty' => '0.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['qty' => '-1.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['unit_cost' => '-0.0001'])))->toThrow(QueryException::class);

    DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['unit_cost' => '0.0000', 'unit_cost_base' => '0.0000', 'line_total' => '0.00']));
    // a rate keeps its four decimals (a 12,2 column would have stored 0.09)
    DB::table('purchase_lines')->insert(rawPurchaseLine($p, $purchaseId, ['unit_cost_base' => '0.0850']));
    expect(DB::table('purchase_lines')->where('purchase_id', $purchaseId)->orderByDesc('id')->value('unit_cost_base'))->toBe('0.0850');
})->group('module:inventory', 'feature');

it('keeps one cost row per item and branch, and a rate that is not negative', function () {
    $p = purchaseParents();
    $row = ['branch_id' => $p['branch']->id, 'item_id' => $p['item'], 'moving_avg_cost' => '0.0883', 'qty_basis' => '7500.0000', 'currency' => 'ETB',
        'as_of' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
    DB::table('item_costs')->insert($row);

    expect(fn () => DB::table('item_costs')->insert($row))->toThrow(QueryException::class)
        ->and(fn () => DB::table('item_costs')->where('item_id', $p['item'])->update(['moving_avg_cost' => '-0.0001']))->toThrow(QueryException::class)
        ->and(DB::table('item_costs')->where('item_id', $p['item'])->value('moving_avg_cost'))->toBe('0.0883');
})->group('module:inventory', 'feature');

it('keeps the history source to purchase or opening', function () {
    $p = purchaseParents();
    $row = fn (array $over = []): array => $over + [
        'branch_id' => $p['branch']->id, 'item_id' => $p['item'], 'source' => 'purchase', 'purchase_line_id' => null,
        'qty_before' => '0.0000', 'cost_before' => null, 'qty_in' => '10.0000', 'unit_cost_in' => '5.0000', 'cost_after' => '5.0000',
        'user_id' => $p['user']->id, 'acting_user_id' => null, 'created_at' => now(),
    ];

    DB::table('item_cost_history')->insert($row());
    DB::table('item_cost_history')->insert($row(['source' => 'opening']));
    expect(fn () => DB::table('item_cost_history')->insert($row(['source' => 'adjustment'])))->toThrow(QueryException::class)
        ->and(DB::table('item_cost_history')->count())->toBe(2);
})->group('module:inventory', 'feature');
