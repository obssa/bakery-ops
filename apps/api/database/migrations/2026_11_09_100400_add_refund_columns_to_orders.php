<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.1b, the phase-4 plan's M8: what came back of an order's money. An order stays `closed` after a refund (OrderStatus does not
 * change); these say how much and when:
 *   orders.refunded_total DECIMAL(12,2) NOT NULL DEFAULT 0, CHECK (refunded_total >= 0). That it stays at or below paid_total is an
 *     application rule of Orders::refund (orders.refund.exceeds_paid), pinned by a test, not a CHECK: a CHECK across two columns of a
 *     row the payments keep changing would fail a void in mid-flight the day a rule changes;
 *   orders.refunded_at DATETIME NULL: the last refund;
 *   order_lines.refunded_at DATETIME NULL: a line is refunded once (order_refund_lines.order_line_id is UNIQUE).
 * On the live database every existing order gets refunded_total 0 (the default; 0 >= 0, so the CHECK holds on every row) and both dates
 * NULL: no UPDATE runs, only the ALTERs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('refunded_total', 12, 2)->default(0)->after('paid_total');
            $table->dateTime('refunded_at')->nullable()->after('closed_at');
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_refunded_total_check CHECK (refunded_total >= 0)');

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dateTime('refunded_at')->nullable()->after('reject_note');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn('refunded_at');
        });
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_refunded_total_check');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['refunded_total', 'refunded_at']);
        });
    }
};
