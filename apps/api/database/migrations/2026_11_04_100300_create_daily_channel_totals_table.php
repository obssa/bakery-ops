<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * daily_channel_totals (phase-4 plan section 3, M14; Analytics, step 4.4a; ADR 0014): per branch, LOCAL day and payment channel, the
 * live payments of the day by received_at (a disputed payment still counts, 10.8 R-Q; a voided one does not) and the refunds by
 * refunded_at. receipts_count and amount; refund_count (money rows) and refund_amount; net_amount = amount - refund_amount (negative
 * for a channel that only paid back); without_reference_count and without_photo_count counted literally, as Payments' reconciliation
 * does; verified_count, disputed_count; duplicate_reference_count (Payments' own R-B rule, through its Facts port). A channel that
 * neither took nor paid back money that day has no row. Written only by Rebuild::day. Derived: no client_uuid, actor stamp or
 * deleted_at. New and empty when deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_channel_totals', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->foreignId('channel_id')->constrained('payment_channels')->restrictOnDelete();
            $table->unsignedInteger('receipts_count')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedInteger('refund_count')->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->unsignedInteger('without_reference_count')->default(0);
            $table->unsignedInteger('without_photo_count')->default(0);
            $table->unsignedInteger('verified_count')->default(0);
            $table->unsignedInteger('disputed_count')->default(0);
            $table->unsignedInteger('duplicate_reference_count')->default(0);
            $table->dateTime('rebuilt_at', 6);

            $table->primary(['branch_id', 'day', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_channel_totals');
    }
};
