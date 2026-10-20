<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money taken for an order (domain-model.md, Payments; docs/phases/phase-1-plan.md section 3, migration 8: the final
 * schema, so Phase 4 adds no column). Never deleted and never edited into something else: a payment is recorded, then
 * verified or disputed, or voided by status (Phase 4), so there is no deleted_at.
 *
 * - client_uuid: the client's id for the payment, unique per branch (ADR 0003), so a replay with a lost
 *   Idempotency-Key answers the same payment.
 * - amount: what the order was paid by this payment; tendered_amount and change_amount: cash only (tendered is what
 *   the customer handed over, change = tendered - amount). All DECIMAL(12,2) in `currency` (ADR 0004).
 * - reference_no: the bank's or the wallet's reference, never logged (the audit snapshot redacts it).
 *   receipt_photo_key: the object-store key of the receipt photo, signed on read (Phase 4; null until then).
 * - Actor stamp (rule 4): received_by_user_id is the person who took the money (the cashier acting by short code on a
 *   shared till, else the account); acting_user_id repeats the short-code person, the audit row names the account;
 *   shift_instance_id has no foreign key until Phase 3 (dependency-graph.md cycle 1); station_id, device_id.
 * - status is VARCHAR with a named CHECK (MariaDB 10.11 and PostgreSQL alike); verified_* and voided_* belong to Phase 4.
 */
return new class extends Migration
{
    private const array STATUSES = ['recorded', 'verified', 'disputed', 'voided'];

    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('channel_id')->constrained('payment_channels')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('tendered_amount', 12, 2)->nullable();
            $table->decimal('change_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('ETB');
            $table->string('reference_no', 64)->nullable();
            $table->string('receipt_photo_key', 255)->nullable();
            $table->string('status', 20)->default('recorded');

            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->dateTime('received_at');

            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['order_id']);
            $table->index(['branch_id', 'received_at']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('.self::list(self::STATUSES).'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }

    /**
     * @param  list<string>  $values
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }
};
