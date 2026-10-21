<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a person can be reached outside the app (phase-2 plan section 3, migration 12, step 2.5b): a browser web push
 * subscription or a Telegram chat (an sms slot exists for a gateway that has not been chosen). address holds the push
 * subscription JSON or the chat id and is encrypted at rest (the model's encrypted cast); address_hash is the sha256 of the
 * push endpoint URL or of the chat id, so "the same subscription again" is found without decrypting anything. One row per
 * person, driver and address (unique), which the actions reuse when a revoked subscription comes back. A row is revoked (the
 * person turned it off, or the push service said it is gone) with revoked_at and soft-deleted when the person removes it.
 *
 * This table is created before notification_deliveries (plan migration 11) because a delivery references its endpoint; the
 * plan numbers them the other way round, the dependency decides the file order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('driver', 10);
            $table->char('address_hash', 64);
            $table->text('address');
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'driver', 'address_hash']);
            $table->index(['branch_id', 'user_id']);
        });

        DB::statement("ALTER TABLE notification_endpoints ADD CONSTRAINT notification_endpoints_driver_check CHECK (driver IN ('push', 'telegram', 'sms'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_endpoints');
    }
};
