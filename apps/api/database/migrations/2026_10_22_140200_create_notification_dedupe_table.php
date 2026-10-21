<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery gate for episodic alerts (phase-2 plan section 3, migration 13; ADR 0011): "have people already been told
 * that this item ran out at this place?". Keyed as the manifest says (item, location, type), as a string such as
 * stock_out:{item}:{location}. Inventory's stock_alert_states decides when an episode starts; this table decides whether
 * Notifications already announced it, so it stays correct however often an event is redelivered and by whom it is produced
 * (Notifications imports no producer, dependency-graph.md cycle 7).
 *
 * At most one ACTIVE row per (branch, key), enforced by the database on both engines with no partial index: is_active is 1
 * while the gate is closed to repeats and NULL once cleared, NULLs are distinct in a unique index, so the unique key
 * (branch_id, dedupe_key, is_active) allows any number of cleared rows and one active one. A claim is therefore one INSERT
 * the unique key arbitrates, never a SELECT ... FOR UPDATE on a row that may not exist (traps.md, "First-row race").
 * Cleared rows stay as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dedupe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('dedupe_key', 120);
            $table->string('type', 40);
            $table->unsignedTinyInteger('is_active')->nullable();
            $table->dateTime('active_since', 6);
            $table->dateTime('cleared_at', 6)->nullable();

            $table->unique(['branch_id', 'dedupe_key', 'is_active'], 'notification_dedupe_one_active');
        });

        DB::statement('ALTER TABLE notification_dedupe ADD CONSTRAINT notification_dedupe_is_active_check CHECK (is_active IS NULL OR is_active = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dedupe');
    }
};
