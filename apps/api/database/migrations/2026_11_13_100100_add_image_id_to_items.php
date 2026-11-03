<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6.1a, phase-6 plan section 3 M2 (ADR 0022): items.image_id, the product's current display photo (an item_images row), or NULL
 * for none. Added after M1 because the two tables point at each other. The foreign key has no cascade: item_images rows are never
 * hard-deleted (a replace or a remove soft-deletes the old row and moves this column in the same transaction). Indexed. Set only by
 * Catalog\Services\ProductPhotos, never a field of PATCH /items/{item}. On the live database: one nullable column on a table of a few
 * dozen rows per branch, every existing row NULL (no photo), so a rollback of the code leaves an unused column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedBigInteger('image_id')->nullable()->after('refund_restock');
            $table->index('image_id', 'items_image_id_index');
            $table->foreign('image_id', 'items_image_id_foreign')->references('id')->on('item_images')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign('items_image_id_foreign');
            $table->dropIndex('items_image_id_index');
            $table->dropColumn('image_id');
        });
    }
};
