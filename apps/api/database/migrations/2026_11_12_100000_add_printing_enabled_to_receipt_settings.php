<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live settings (phase-5 plan, "Go-live decisions (2026-11-02)"): the shop may go live without its receipt printer, so the admin can
 * turn printing off in Admin > Payments > Receipts.
 *   printing_enabled  BOOLEAN NOT NULL DEFAULT true: on, POST /orders/{order}/print queues a job for the print bridge as before; off, it
 *                     answers 409 payments.print.disabled and the till offers the receipt link and its QR code instead.
 * The live table holds one row per branch: ADD COLUMN with a default fills every row with true, so today's behaviour is kept and no row
 * is updated; Ramin turns it off himself. Portable DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_settings', function (Blueprint $table): void {
            $table->boolean('printing_enabled')->default(true)->after('print_logo');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_settings', function (Blueprint $table): void {
            $table->dropColumn('printing_enabled');
        });
    }
};
