<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock counts (phase-3 plan section 3, migration 9; Shifts, step 3.3a): a count of one location at one shift boundary. It moves
 * along App\Modules\Shifts\Enums\CountStatus and is never deleted (a recount or an unlock is a new row, `seq` + 1, that
 * supersedes it). The lock columns are created now so that step 3.4 adds no column ("final schema, never throwaway"): the chain
 * (chain_seq, previous_hash, payload_hash, payload_version, canonical_payload, locked_at, locked_by_user_id) is all set exactly
 * when the status is `locked` (CHECK), and the unique (branch_id, location_id, chain_seq) lets the database claim a link's
 * sequence (NULL for unlocked rows never collides).
 *
 * - client_uuid is derived by the server (UUIDv5 of count:{shift}:{location}:{type}:{seq}), so a retried start or close finds the
 *   same row; unique per branch (ADR 0003).
 * - snapshot_at is DATETIME(6): the instant the balances were read under the row locks at submit. ledger_movement_id is the
 *   newest movement of the branch at that instant, what "since the count" is measured from.
 * - Rule R-A (at most one unresolved count per place) is code, not a constraint (section 10.8); the property test pins it.
 * - Actor stamp without shift_instance_id: the row names its shift. version is the live feed's merge key.
 */
return new class extends Migration
{
    private const array TYPES = ['opening', 'closing'];

    private const array STATUSES = ['draft', 'awaiting_cosign', 'pending_approval', 'approved', 'locked', 'rejected'];

    private const array DECISIONS = ['approved', 'rejected'];

    private const array CAUSES = ['recount', 'unlock'];

    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('type', 8);
            $table->unsignedTinyInteger('seq')->default(1);
            $table->string('status', 20)->default('draft');

            $table->foreignId('counted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('snapshot_at', 6)->nullable();
            $table->unsignedBigInteger('ledger_movement_id')->nullable();
            $table->dateTime('submitted_at')->nullable();

            $table->foreignId('cosigned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cosigned_at')->nullable();
            $table->string('decision', 10)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->string('supersede_cause', 10)->nullable();
            $table->string('unlock_reason', 255)->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->dateTime('superseded_at')->nullable();

            $table->unsignedInteger('chain_seq')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedTinyInteger('payload_version')->nullable();
            $table->mediumText('canonical_payload')->nullable();
            $table->dateTime('locked_at', 6)->nullable();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('ledger_movement_id', 'stock_counts_ledger_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('superseded_by_id', 'stock_counts_superseded_by_fk')->references('id')->on('stock_counts')->restrictOnDelete();

            $table->unique(['branch_id', 'client_uuid'], 'stock_counts_client_uuid_unique');
            $table->unique(['shift_instance_id', 'location_id', 'type', 'seq'], 'stock_counts_slot_unique');
            $table->unique(['branch_id', 'location_id', 'chain_seq'], 'stock_counts_chain_unique');
            $table->index(['location_id', 'type', 'status'], 'stock_counts_place_status_index');
            $table->index(['branch_id', 'status'], 'stock_counts_queue_index');
        });

        $in = static fn (array $values): string => implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
        $types = $in(self::TYPES);
        $statuses = $in(self::STATUSES);
        $decisions = $in(self::DECISIONS);
        $causes = $in(self::CAUSES);

        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_seq_check CHECK (seq >= 1)');
        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_decision_check CHECK (decision IS NULL OR decision IN ({$decisions}))");
        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_supersede_cause_check CHECK (supersede_cause IS NULL OR supersede_cause IN ({$causes}))");
        DB::statement('ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_chain_seq_check CHECK (chain_seq IS NULL OR chain_seq >= 1)');
        DB::statement('ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_decided_check CHECK ((decision IS NOT NULL) = (decided_at IS NOT NULL))');
        DB::statement(
            "ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_lock_columns_check CHECK ((status = 'locked') = "
            .'(payload_hash IS NOT NULL AND previous_hash IS NOT NULL AND chain_seq IS NOT NULL AND canonical_payload IS NOT NULL AND locked_at IS NOT NULL))',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
