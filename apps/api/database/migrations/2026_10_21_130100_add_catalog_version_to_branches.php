<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * branches.catalog_version (step 1.7): a counter every write to the catalog tables behind GET /menu bumps by one
 * (items, categories, modifiers, station routing, recipes and their lines, prices; Catalog's BumpsCatalogVersion on
 * the models). Timestamps have whole-second precision, so two edits in the same second share an updated_at; the counter
 * does not, and the menu's ETag reads it with one primary-key lookup instead of eight aggregate scans.
 * Purely additive and backwards compatible (a rollback of the code leaves an unused column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->unsignedBigInteger('catalog_version')->default(0)->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('catalog_version');
        });
    }
};
