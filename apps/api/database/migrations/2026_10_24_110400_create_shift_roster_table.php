<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The roster: who is on a shift (phase-3 plan section 3, migration 6; Shifts, step 3.2a; ADR 0013 rule 4).
 *
 * - On duty = a row with joined_at set and left_at null on the running shift. joined_at is null while someone is only scheduled.
 * - source: scheduled | started | call_in | joined (CHECK shift_roster_source_check).
 * - Soft-deleted only while the shift has not started (a scheduled person removed from a scheduled shift); once it has started
 *   the row is history and only left_at moves. Unique (shift_instance_id, user_id), so a person is on a shift once.
 * - CHECK left_at IS NULL OR (joined_at IS NOT NULL AND left_at >= joined_at).
 * - Actor stamp: created_by_user_id, acting_user_id, device_id (shift_instance_id here is the parent, not the stamp).
 */
return new class extends Migration
{
    private const array SOURCES = ['scheduled', 'started', 'call_in', 'joined'];

    public function up(): void
    {
        Schema::create('shift_roster', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('source', 12);
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shift_instance_id', 'user_id'], 'shift_roster_person_unique');
            $table->index(['user_id', 'joined_at']);
        });

        $sources = implode(', ', array_map(static fn (string $source): string => "'{$source}'", self::SOURCES));
        DB::statement("ALTER TABLE shift_roster ADD CONSTRAINT shift_roster_source_check CHECK (source IN ({$sources}))");
        DB::statement('ALTER TABLE shift_roster ADD CONSTRAINT shift_roster_left_check CHECK (left_at IS NULL OR (joined_at IS NOT NULL AND left_at >= joined_at))');
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_roster');
    }
};
