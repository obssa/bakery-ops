<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->group('module:inventory', 'invariants');

/*
 * stock_movement_reversals (step 4.1b, phase-4 plan section 3, M6): one row per reversing movement, naming the `return` it wrote
 * (movement_id) and the original it reverses (reverses_movement_id). Both are UNIQUE: an original is reversed once, as a fact of the
 * database rather than of a read, without a partial index. Append-only through triggers; branch_id NOT NULL with its foreign key and an
 * index; written only by the Ledger, in the same transaction as the movement (LedgerIsSoleWriterTest keeps it there). Rows written raw.
 */

/** @return array{branch: Branch, sale: int, back: int, other: int} two movements to point at, and a third */
function reversalMovements(): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $item = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);
    $location = Location::factory()->create(['branch_id' => $branch->id]);
    $row = fn (string $qty, string $reason, array $place) => DB::table('stock_movements')->insertGetId($place + [
        'branch_id' => $branch->id, 'item_id' => $item->id, 'qty' => $qty, 'reason' => $reason, 'ref_type' => 'probe', 'user_id' => $user->id, 'created_at' => now(),
    ]);

    return [
        'branch' => $branch,
        'sale' => $row('-1.0000', 'sale', ['from_location_id' => $location->id]),
        'back' => $row('1.0000', 'return', ['to_location_id' => $location->id]),
        'other' => $row('1.0000', 'return', ['to_location_id' => $location->id]),
    ];
}

it('names the return and the original it reverses, each unique, with branch_id, foreign keys and no updated_at', function () {
    $columns = array_map(fn ($c) => (string) $c->name, DB::select(
        'select column_name as name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
        [dbSchema(), 'stock_movement_reversals'],
    ));
    expect($columns)->toBe(['id', 'branch_id', 'movement_id', 'reverses_movement_id', 'created_at']);

    $references = fn (string $column): ?string => DB::selectOne(
        'select referenced_table_name as ref from information_schema.key_column_usage
          where table_schema = ? and table_name = ? and column_name = ? and referenced_table_name is not null',
        [dbSchema(), 'stock_movement_reversals', $column],
    )?->ref;
    expect($references('branch_id'))->toBe('branches')
        ->and($references('movement_id'))->toBe('stock_movements')
        ->and($references('reverses_movement_id'))->toBe('stock_movements')
        ->and(Schema::hasColumns('stock_movement_reversals', ['updated_at']))->toBeFalse()
        ->and(Schema::hasColumns('stock_movement_reversals', ['deleted_at']))->toBeFalse();

    $m = reversalMovements();
    DB::table('stock_movement_reversals')->insert(['branch_id' => $m['branch']->id, 'movement_id' => $m['back'], 'reverses_movement_id' => $m['sale'], 'created_at' => now()]);

    expect(fn () => DB::table('stock_movement_reversals')->insert(['branch_id' => $m['branch']->id, 'movement_id' => $m['other'], 'reverses_movement_id' => $m['sale'], 'created_at' => now()]))
        ->toThrow(QueryException::class, 'reverses_movement_id')
        ->and(fn () => DB::table('stock_movement_reversals')->insert(['branch_id' => $m['branch']->id, 'movement_id' => $m['back'], 'reverses_movement_id' => $m['other'], 'created_at' => now()]))
        ->toThrow(QueryException::class, 'movement_id')
        ->and(fn () => DB::table('stock_movement_reversals')->insert(['branch_id' => $m['branch']->id, 'movement_id' => 987654321, 'reverses_movement_id' => $m['other'], 'created_at' => now()]))
        ->toThrow(QueryException::class);
});

it('refuses UPDATE and DELETE through triggers named stock_movement_reversals_forbid_update_delete_*', function () {
    $m = reversalMovements();
    $id = DB::table('stock_movement_reversals')->insertGetId(['branch_id' => $m['branch']->id, 'movement_id' => $m['back'], 'reverses_movement_id' => $m['sale'], 'created_at' => now()]);

    $triggers = DB::select('select trigger_name as name from information_schema.triggers where trigger_schema = ? and event_object_table = ?', [dbSchema(), 'stock_movement_reversals']);
    expect($triggers)->not->toBeEmpty()
        ->and(collect($triggers)->pluck('name')->every(fn (string $n): bool => str_starts_with($n, 'stock_movement_reversals_forbid_update_delete')))->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('stock_movement_reversals')->where('id', $id)->update(['reverses_movement_id' => $m['other']])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_movement_reversals')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only')
        ->and((int) DB::table('stock_movement_reversals')->where('id', $id)->value('reverses_movement_id'))->toBe($m['sale']);
});
