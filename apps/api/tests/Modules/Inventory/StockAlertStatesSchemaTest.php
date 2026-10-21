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

/*
 * Step 2.1 (F1; phase-2 plan section 3, migration 2): stock_alert_states holds one row per episode of "this item ran out
 * (or ran low) at this place". At most one episode may be open per item, place and type, and the database enforces it
 * itself on both engines with no partial index: is_active is 1 while the episode is open and NULL once it is cleared, and
 * NULLs are distinct in a unique index, so the unique key (item_id, location_key, type, is_active) allows any number of
 * cleared episodes and exactly one open one. location_key is the location id, or 0 for a branch-wide rule (used from 2.6).
 */

function alertColumn(string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable, column_default as dflt
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), 'stock_alert_states', $column],
    );
}

/** @return array<string, mixed> a raw episode row for a fresh branch, item and place; $over replaces any column */
function alertRow(array $over = []): array
{
    static $branch = null, $item = null, $location = null, $user = null;
    if (! ($over['_reuse'] ?? false) || $branch === null) {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);
        $location = Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $branch->id]);
    }
    unset($over['_reuse']);

    return $over + [
        'branch_id' => $branch->id, 'item_id' => $item->id, 'location_id' => $location->id, 'location_key' => $location->id,
        'type' => 'out', 'is_active' => 1, 'active_since' => now(), 'cleared_at' => null,
        'available_at_start' => '0.0000', 'threshold_qty' => null, 'caused_by_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

it('creates stock_alert_states with branch_id NOT NULL and DECIMAL(14,4) quantities', function () {
    expect(Schema::hasTable('stock_alert_states'))->toBeTrue();
    expect(strtoupper((string) alertColumn('branch_id')?->nullable))->toBe('NO');
    foreach (['available_at_start', 'threshold_qty'] as $column) {
        $c = alertColumn($column);
        expect($c)->not->toBeNull("{$column}")
            ->and((int) $c?->p)->toBe(14)
            ->and((int) $c?->s)->toBe(4);
    }
    $fk = DB::selectOne(
        "select 1 as ok from information_schema.key_column_usage
          where table_schema = ? and table_name = 'stock_alert_states' and column_name = 'branch_id' and referenced_table_name = 'branches'",
        [dbSchema()],
    );
    expect($fk)->not->toBeNull()
        ->and(strtoupper((string) alertColumn('location_id')?->nullable))->toBe('YES', 'a branch-wide rule has no location')
        ->and(strtoupper((string) alertColumn('location_key')?->nullable))->toBe('NO')
        ->and(strtoupper((string) alertColumn('is_active')?->nullable))->toBe('YES', 'NULL once the episode is cleared');
})->group('module:inventory', 'feature');

it('refuses a second open episode for the same item, place and type', function () {
    DB::table('stock_alert_states')->insert(alertRow());

    expect(fn () => DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true])))->toThrow(QueryException::class);
    expect(DB::table('stock_alert_states')->count())->toBe(1);
})->group('module:inventory', 'feature');

it('allows any number of cleared episodes beside one open one, and an open one of another type or place', function () {
    DB::table('stock_alert_states')->insert(alertRow());
    foreach ([1, 2, 3] as $n) {
        DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true, 'is_active' => null, 'cleared_at' => now()]));
    }
    DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true, 'type' => 'low', 'threshold_qty' => '5000.0000']));
    DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true, 'location_id' => null, 'location_key' => 0]));

    expect(DB::table('stock_alert_states')->count())->toBe(6)
        ->and(DB::table('stock_alert_states')->where('is_active', 1)->count())->toBe(3);
    // and a second branch-wide open episode for the same item and type is refused, though location_id is NULL
    expect(fn () => DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true, 'location_id' => null, 'location_key' => 0])))->toThrow(QueryException::class);
})->group('module:inventory', 'feature');

it('keeps the type and the open flag to their lists', function () {
    expect(fn () => DB::table('stock_alert_states')->insert(alertRow(['type' => 'broken'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true, 'is_active' => 0])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('stock_alert_states')->insert(alertRow(['_reuse' => true, 'is_active' => 2])))->toThrow(QueryException::class);
    expect(DB::table('stock_alert_states')->count())->toBe(0);
})->group('module:inventory', 'feature');
