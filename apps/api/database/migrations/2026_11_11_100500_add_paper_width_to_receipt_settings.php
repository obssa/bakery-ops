<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5.3a, phase-5 plan section 3 M4 and 10.2 D18: receipt_settings learns the receipt printer's paper.
 *   paper_width_mm  TINYINT UNSIGNED NOT NULL DEFAULT 80, CHECK receipt_settings_paper_width_check IN (58, 80): 80 mm prints 576 dots,
 *                   58 mm 384 (ReceiptRaster); the bridge's claim and GET /orders/{order}/receipt.escpos draw at it unless told a width;
 *   print_logo      BOOLEAN NOT NULL DEFAULT false, reserved (no logo upload in Phase 5).
 * The live table holds one row per branch: ADD COLUMN with a default fills both columns of every row (80 mm, no logo), so the CHECK holds
 * on them and no row is updated. Portable DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('paper_width_mm')->default(80)->after('ethiopian_dates');
            $table->boolean('print_logo')->default(false)->after('paper_width_mm');
        });

        DB::statement('ALTER TABLE receipt_settings ADD CONSTRAINT receipt_settings_paper_width_check CHECK (paper_width_mm IN (58, 80))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receipt_settings DROP CONSTRAINT receipt_settings_paper_width_check');

        Schema::table('receipt_settings', function (Blueprint $table): void {
            $table->dropColumn(['paper_width_mm', 'print_logo']);
        });
    }
};
