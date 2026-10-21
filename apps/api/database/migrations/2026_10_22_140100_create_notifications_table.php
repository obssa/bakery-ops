<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app inbox (phase-2 plan section 3, migration 10): one row per recipient per event, with the title and the body in
 * both languages (the reader's locale picks one when it is read, and the external drivers of step 2.5b pick the
 * recipient's), the event's payload as ids and decimal strings, and the path the line opens. The inbox line IS the in_app
 * delivery: it exists for every notification of every type, so a missing push or Telegram never loses an alert.
 *
 * (user_id, dedupe_key) is unique: a redelivered event cannot write a second line for the same person. NULLs are distinct
 * in a unique index, so a line without a key is never refused. The only column that ever changes is read_at; the row is
 * history and is never deleted. It is written by the system on behalf of an event, not by a person's operational write, so
 * it carries no actor stamp: the cause is payload.caused_by_user_id. created_at has microseconds because it is the clock of
 * the polling cursor (Shared Live OverlapCursor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('severity', 10);
            $table->string('title_en', 160);
            $table->string('title_am', 160);
            $table->string('body_en', 500);
            $table->string('body_am', 500);
            $table->json('payload');
            $table->string('deep_link', 255);
            $table->string('dedupe_key', 120)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['branch_id', 'user_id', 'id']);
            $table->index(['user_id', 'read_at']);
            $table->unique(['user_id', 'dedupe_key']);
        });

        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('stock_low', 'stock_out', 'production_requested', 'production_assigned', 'batch_pending_confirmation', 'batch_done', 'transfer_requested', 'transfer_decided', 'line_ready', 'line_rejected'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_severity_check CHECK (severity IN ('info', 'warning', 'critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
