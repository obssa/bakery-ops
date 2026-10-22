<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shift templates (phase-3 plan section 3, migration 3; Shifts, step 3.2a): a named window of the day ("Day 06:00 to 14:00",
 * "Night 22:00 to 06:00") on the days of the week, which the schedule turns into shift instances.
 *
 * - start_time and end_time are TIME, the branch's local wall-clock times. end_time earlier than start_time is the
 *   cross-midnight window (a Night); equal times are refused, a template is never exactly 24 hours (CHECK
 *   shift_templates_window_check). Section 10.2 and App\Modules\Shifts\Support\ShiftWindow decide which window holds an instant.
 * - days_mask: bit 0 is Monday .. bit 6 Sunday, the ISO weekday of the day the window STARTS (CHECK 1 to 127). The API says
 *   days_of_week: [1..7].
 * - Configuration, so soft-deleted and audited; no client_uuid. Editing a template never changes an instance already made.
 * - Actor stamp as on the other configuration tables: created_by_user_id, acting_user_id, shift_instance_id (its foreign key
 *   comes with migration 7 of the plan, once shift_instances exists), actor_station_id, device_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name_en', 80);
            $table->string('name_am', 80);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('days_mask')->default(127);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
        });

        DB::statement('ALTER TABLE shift_templates ADD CONSTRAINT shift_templates_days_mask_check CHECK (days_mask BETWEEN 1 AND 127)');
        DB::statement('ALTER TABLE shift_templates ADD CONSTRAINT shift_templates_window_check CHECK (start_time <> end_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
    }
};
