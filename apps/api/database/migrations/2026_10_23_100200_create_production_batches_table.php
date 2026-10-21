<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production batches (phase-2 plan section 3, migration 16; Production, step 2.7a). A batch is the work done for one request
 * (request_id is unique): `started` when the baker presses Start, `finished` when Done is pressed at a station that needs an
 * overseer, `confirmed` once the stock is written. It moves along App\Modules\Production\Enums\BatchStatus and is never deleted.
 *
 * - recipe_id and recipe_version are the version the baker saw; consumption always uses it. consume_location_id and
 *   output_location_id are resolved at start, so a later change of configuration does not move an open batch.
 * - planned_qty, produced_qty and waste_qty are DECIMAL(14,4) in the product's base unit.
 * - output_movement_id names the production_output movement a confirm wrote (a foreign key to the ledger; the consumption rows
 *   are found by their derived client_uuids).
 * - CHECKs: planned_qty > 0; produced_qty and waste_qty never negative and, once finished, both set with a sum above zero;
 *   finished_at / finished_by_user_id set for a finished or confirmed batch; the confirmed_* columns set exactly when
 *   confirmed; and the confirmer is not the person who finished it, unless both happened at the same instant (a station that
 *   needs no overseer confirms in the write that finishes). A send back keeps the finished_* columns for the card.
 * - Actor stamp as on production_requests; started_by_user_id, finished_by_user_id and confirmed_by_user_id are the people.
 */
return new class extends Migration
{
    private const array STATUSES = ['started', 'finished', 'confirmed'];

    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('request_id')->constrained('production_requests')->restrictOnDelete();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->string('status', 10)->default('started');
            $table->foreignId('recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->unsignedInteger('recipe_version');
            $table->decimal('planned_qty', 14, 4);
            $table->decimal('produced_qty', 14, 4)->nullable();
            $table->decimal('waste_qty', 14, 4)->nullable();
            $table->foreignId('consume_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('output_location_id')->constrained('locations')->restrictOnDelete();
            $table->dateTime('started_at');
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('finished_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('send_back_note', 255)->nullable();
            $table->unsignedTinyInteger('send_back_count')->default(0);
            $table->foreignId('output_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid']);
            $table->unique('request_id');
            $table->index(['branch_id', 'status']);
        });

        $statuses = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::STATUSES));
        DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE production_batches ADD CONSTRAINT production_batches_planned_check CHECK (planned_qty > 0)');
        DB::statement('ALTER TABLE production_batches ADD CONSTRAINT production_batches_produced_check CHECK (produced_qty IS NULL OR produced_qty >= 0)');
        DB::statement('ALTER TABLE production_batches ADD CONSTRAINT production_batches_waste_check CHECK (waste_qty IS NULL OR waste_qty >= 0)');
        DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_finished_qty_check CHECK (status = 'started' OR (produced_qty IS NOT NULL AND waste_qty IS NOT NULL AND produced_qty + waste_qty > 0))");
        DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_finished_by_check CHECK (status = 'started' OR (finished_at IS NOT NULL AND finished_by_user_id IS NOT NULL))");
        DB::statement(
            "ALTER TABLE production_batches ADD CONSTRAINT production_batches_confirmed_when_confirmed CHECK ((status = 'confirmed' AND confirmed_at IS NOT NULL AND confirmed_by_user_id IS NOT NULL) OR (status <> 'confirmed' AND confirmed_at IS NULL AND confirmed_by_user_id IS NULL))",
        );
        DB::statement(
            'ALTER TABLE production_batches ADD CONSTRAINT production_batches_confirmer_differs CHECK (confirmed_by_user_id IS NULL OR finished_by_user_id IS NULL OR confirmed_by_user_id <> finished_by_user_id OR confirmed_at = finished_at)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
