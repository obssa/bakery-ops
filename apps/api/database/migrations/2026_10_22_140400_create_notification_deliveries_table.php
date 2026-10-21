<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was sent outside the app (phase-2 plan section 3, migration 11, step 2.5b): one row per notification line and endpoint
 * it is to be pushed to, with its status (pending while it is tried, sent, failed after the last try, gone when the push
 * service or Telegram says the address no longer exists), how many times it was tried, and the last error (text only, never a
 * key or a token). The inbox line itself is the in_app delivery and has no row here. A driver that is switched off writes no
 * row. History: a delivery is never deleted; the inbox line does not depend on it, so a failed send changes nothing there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('notification_id')->constrained('notifications')->restrictOnDelete();
            $table->string('driver', 10);
            $table->foreignId('endpoint_id')->constrained('notification_endpoints')->restrictOnDelete();
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['branch_id', 'notification_id']);
        });

        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_driver_check CHECK (driver IN ('push', 'telegram', 'sms'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending', 'sent', 'failed', 'gone'))");
        DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_attempts_check CHECK (attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
