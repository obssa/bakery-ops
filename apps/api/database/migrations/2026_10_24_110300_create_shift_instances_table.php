<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shift instances (phase-3 plan section 3, migration 5; Shifts, step 3.2a; ADR 0013): a template on a date at a station, or
 * an ad-hoc shift. It moves along App\Modules\Shifts\Enums\ShiftStatus and is never deleted (it ends locked or cancelled).
 *
 * - `date` is the LOCAL start date in the branch's timezone (section 10.2), not the UTC date and not the date it ends.
 *   starts_at and ends_at are the template window of that date in UTC, or null for an ad-hoc shift.
 * - slot_key is the template id, or 0 when there is none (CHECK slot_key = COALESCE(template_id, 0)); with `sequence` it makes
 *   the unique key (station_id, date, slot_key, sequence) work although template_id is NULL for an ad-hoc shift: the 2nd, 3rd
 *   instance of one slot on one date (a station that closed early and started again, two call-ins a night) takes the next sequence.
 * - open_key is 1 while the status is opening, open or counting, else NULL (CHECK keeps the two in step). NULLs are distinct in
 *   a unique index, so the unique key (station_id, open_key) is "at most one running shift per station", enforced by the
 *   database on MariaDB and PostgreSQL alike with no partial index and no generated column (ADR 0011's technique): two people
 *   pressing Start at once, one insert wins and the other is refused by the key.
 * - CHECKs repeat the enum lists (status, origin, opening_mode) and tie locked_at, cancelled_at and opened_at to the status.
 * - Actor stamp without shift_instance_id: the row is the shift. started_by, opened_by, closed_by and cancelled_by are the people.
 * - version is the live feed's merge key (ADR 0009).
 */
return new class extends Migration
{
    private const array STATUSES = ['scheduled', 'opening', 'open', 'counting', 'pending_approval', 'locked', 'cancelled'];

    private const array ORIGINS = ['scheduled', 'started', 'call_in'];

    private const array OPENING_MODES = ['counted', 'carried'];

    public function up(): void
    {
        Schema::create('shift_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('shift_templates')->restrictOnDelete();
            $table->unsignedBigInteger('slot_key')->default(0);
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->date('date');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('origin', 12);
            $table->string('status', 20)->default('scheduled');
            $table->unsignedTinyInteger('open_key')->nullable();
            $table->string('opening_mode', 10)->nullable();

            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('closing_started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid'], 'shift_instances_client_uuid_unique');
            $table->unique(['station_id', 'open_key'], 'shift_instances_one_running_per_station');
            $table->unique(['station_id', 'date', 'slot_key', 'sequence'], 'shift_instances_slot_unique');
            $table->index(['branch_id', 'date']);
            $table->index(['branch_id', 'status']);
            $table->index(['station_id', 'status']);
        });

        $in = static fn (array $values): string => implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
        $statuses = $in(self::STATUSES);
        $origins = $in(self::ORIGINS);
        $modes = $in(self::OPENING_MODES);
        $running = $in(['opening', 'open', 'counting']);
        $opened = $in(['open', 'counting', 'pending_approval', 'locked']);

        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_status_check CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_origin_check CHECK (origin IN ({$origins}))");
        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_opening_mode_check CHECK (opening_mode IS NULL OR opening_mode IN ({$modes}))");
        DB::statement('ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_sequence_check CHECK (sequence >= 1)');
        DB::statement('ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_slot_key_check CHECK (slot_key = COALESCE(template_id, 0))');
        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_open_key_check CHECK ((open_key IS NULL OR open_key = 1) AND ((open_key IS NOT NULL) = (status IN ({$running}))))");
        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_locked_at_check CHECK ((status = 'locked') = (locked_at IS NOT NULL))");
        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_cancelled_at_check CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))");
        DB::statement("ALTER TABLE shift_instances ADD CONSTRAINT shift_instances_opened_at_check CHECK (status NOT IN ({$opened}) OR opened_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_instances');
    }
};
