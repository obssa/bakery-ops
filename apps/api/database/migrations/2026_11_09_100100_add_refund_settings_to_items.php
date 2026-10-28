<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.1b, Ramin's answer to R-C (2026-10-28): two refund settings per item, which the admin sets and a refund follows (Orders reads them
 * through Catalog\Contracts\RefundPolicy at the moment of the refund; the till chooses nothing):
 *   refundable      BOOLEAN NOT NULL DEFAULT TRUE: may the product be refunded at all;
 *   refund_restock  BOOLEAN NOT NULL DEFAULT FALSE: does a refunded unit go back into stock (a cake from the display case), or not (a
 *                   burger made to order, whose ingredients are used).
 * ADD COLUMN gives every existing row the column defaults; backfill() then sets the natural values once, and the admin changes them
 * afterwards: refund_restock = TRUE for the products sold from stock (fulfilment to_stock or bought_in: their sale takes the product
 * itself out of stock), refundable = FALSE for the ingredients (never sold). Products made to order keep refundable TRUE, refund_restock
 * FALSE. Soft-deleted rows get the same values (a refund of a line sold before the product was removed reads them). The two UPDATEs touch
 * items only, never updated_at, and a second run changes nothing. Portable: plain booleans and UPDATEs on MariaDB 10.11 and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->boolean('refundable')->default(true)->after('production_station_id');
            $table->boolean('refund_restock')->default(false)->after('refundable');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn(['refundable', 'refund_restock']);
        });
    }

    /** The natural values of the existing rows (also run by tests/Modules/Catalog/Feature/ItemRefundSettingsTest.php). */
    public function backfill(): void
    {
        DB::table('items')->where('kind', 'product')->whereIn('fulfilment', ['to_stock', 'bought_in'])->where('refund_restock', false)
            ->update(['refund_restock' => true]);
        DB::table('items')->where('kind', 'ingredient')->where('refundable', true)
            ->update(['refundable' => false]);
    }
};
