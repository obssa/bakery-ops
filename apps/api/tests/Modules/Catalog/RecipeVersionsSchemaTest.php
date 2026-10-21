<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 2.4a (phase-2 plan section 3, migration 9): recipes gain the publish columns and the database's own guarantee of one
 * live active version per product. MariaDB enforces it with a STORED generated column and a unique index, PostgreSQL with a
 * partial unique index; both leave inactive versions and soft-deleted rows free, so history can grow without limit.
 */

/** @return array{branch: Branch, user: User, product: int, other: int, unit: int} the rows a recipe hangs on */
function recipeParents(): array
{
    $branch = Branch::factory()->create();

    return [
        'branch' => $branch,
        'user' => User::factory()->create(['branch_id' => $branch->id]),
        'product' => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id])->id,
        'other' => Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id])->id,
        'unit' => catalogUnit('pcs')->id,
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawRecipe(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'product_item_id' => $p['product'], 'yield_qty' => '10.0000', 'yield_unit_id' => $p['unit'],
        'version' => 1, 'is_active' => 1, 'published_at' => null, 'published_by_user_id' => null, 'change_note' => null,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
    ];
}

it('adds the publish columns to recipes: published_at, published_by_user_id (a user) and change_note', function () {
    expect(Schema::hasTable('recipes'))->toBeTrue()
        ->and(Schema::hasColumns('recipes', ['published_at', 'published_by_user_id', 'change_note']))->toBeTrue();

    $p = recipeParents();
    DB::table('recipes')->insert(rawRecipe($p, ['published_at' => now(), 'published_by_user_id' => $p['user']->id, 'change_note' => 'More flour']));
    expect(DB::table('recipes')->where('product_item_id', $p['product'])->value('change_note'))->toBe('More flour');

    expect(fn () => DB::table('recipes')->insert(rawRecipe($p, ['version' => 2, 'is_active' => 0, 'published_by_user_id' => 999999])))->toThrow(QueryException::class);
})->group('module:catalog', 'feature');

it('allows one active version per product and refuses a second, whatever the code does', function () {
    expect(Schema::hasTable('recipes'))->toBeTrue();
    $p = recipeParents();
    DB::table('recipes')->insert(rawRecipe($p));

    expect(fn () => DB::table('recipes')->insert(rawRecipe($p, ['version' => 2])))->toThrow(QueryException::class)
        ->and(DB::table('recipes')->where('product_item_id', $p['product'])->count())->toBe(1);

    // another product, and an inactive version of the same one, are fine
    DB::table('recipes')->insert(rawRecipe($p, ['product_item_id' => $p['other']]));
    DB::table('recipes')->insert(rawRecipe($p, ['version' => 2, 'is_active' => 0]));
    DB::table('recipes')->insert(rawRecipe($p, ['version' => 3, 'is_active' => 0]));
    expect(DB::table('recipes')->where('product_item_id', $p['product'])->count())->toBe(3);
})->group('module:catalog', 'feature');

it('refuses to activate an inactive version while another is active, and allows the swap in the right order', function () {
    expect(Schema::hasTable('recipes'))->toBeTrue();
    $p = recipeParents();
    DB::table('recipes')->insert(rawRecipe($p));
    DB::table('recipes')->insert(rawRecipe($p, ['version' => 2, 'is_active' => 0]));

    expect(fn () => DB::table('recipes')->where('product_item_id', $p['product'])->where('version', 2)->update(['is_active' => 1]))->toThrow(QueryException::class);

    DB::table('recipes')->where('version', 1)->where('product_item_id', $p['product'])->update(['is_active' => 0]);
    DB::table('recipes')->where('version', 2)->where('product_item_id', $p['product'])->update(['is_active' => 1]);
    expect(DB::table('recipes')->where('product_item_id', $p['product'])->where('is_active', 1)->value('version'))->toBe(2);
})->group('module:catalog', 'feature');

it('does not count a soft-deleted active version against the product', function () {
    expect(Schema::hasTable('recipes'))->toBeTrue();
    $p = recipeParents();
    DB::table('recipes')->insert(rawRecipe($p, ['deleted_at' => now()]));
    DB::table('recipes')->insert(rawRecipe($p, ['version' => 2]));

    expect(DB::table('recipes')->where('product_item_id', $p['product'])->whereNull('deleted_at')->where('is_active', 1)->count())->toBe(1);
})->group('module:catalog', 'feature');
