<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recipe versions (phase-2 plan section 3, migration 9; Catalog, step 2.4a). A recipe row is one version of a product's bill of
 * materials; publishing writes the next version and retires the one before (App\Modules\Catalog\Services\RecipeBook::publish).
 *
 * - published_at / published_by_user_id / change_note say when, by whom and why a version was published. Version 1 made by
 *   PUT /items/{item}/recipe has no publisher; existing rows get published_at from created_at. Recipes stay soft-deleted
 *   configuration with no actor-stamp columns: publishing is an audited action and the audit row carries the stamp.
 * - One live active version per product, guaranteed by the database itself, whatever the code does. MariaDB and MySQL have
 *   no partial index, so a STORED generated column active_product_key holds the product id while the version is active and
 *   not soft-deleted, NULL otherwise, under a UNIQUE index (NULLs never collide); PostgreSQL gets the partial unique index
 *   recipes_one_active. Version 1 of every existing recipe is active, so the key holds on existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dateTime('published_at')->nullable()->after('is_active');
            $table->foreignId('published_by_user_id')->nullable()->after('published_at')->constrained('users')->restrictOnDelete();
            $table->string('change_note', 255)->nullable()->after('published_by_user_id');
        });

        DB::table('recipes')->whereNull('published_at')->update(['published_at' => DB::raw('created_at')]);

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE recipes ADD COLUMN active_product_key BIGINT UNSIGNED GENERATED ALWAYS AS '
                .'(CASE WHEN is_active = 1 AND deleted_at IS NULL THEN product_item_id END) STORED, '
                .'ADD UNIQUE INDEX recipes_one_active (active_product_key)',
            );
        } elseif ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX recipes_one_active ON recipes (product_item_id) WHERE is_active AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE recipes DROP INDEX recipes_one_active, DROP COLUMN active_product_key');
        } elseif ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS recipes_one_active');
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropColumn(['published_at', 'change_note']);
        });
    }
};
