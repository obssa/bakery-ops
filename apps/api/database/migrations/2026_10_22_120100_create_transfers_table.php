<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers (phase-2 plan section 3, migration 8; Inventory, step 2.3). A transfer asks for stock to be moved between two
 * locations of the branch (usually the store to a station): it is `requested` until someone who works at the source decides.
 * A confirm writes the two `transfer` movements together through the Ledger (out_movement_id and in_movement_id name them);
 * a reject writes none. Never deleted: it ends confirmed or rejected by status (App\Modules\Inventory\Enums\TransferStatus),
 * so there is no deleted_at.
 *
 * - qty is DECIMAL(14,4) in the item's base unit (the unit the screen typed it in is converted at the request).
 * - client_uuid (the screen's offline id) is unique per branch (ADR 0003): a replay with a lost key finds the same row.
 * - production_request_id is set when the request was raised from a production card; a plain id, Inventory never reads Production.
 * - CHECKs: status is the machine's list, qty > 0, the two ends differ, and the decision (decided_by_user_id, decided_at) is set
 *   exactly when the status is not `requested`.
 * - Actor stamp (rule 4): created_by_user_id (the logged-in account), acting_user_id, shift_instance_id (no foreign key until
 *   Phase 3), actor_station_id, device_id. requested_by_user_id is the person who asked (the one acting, else the account) and
 *   decided_by_user_id the person who decided. version counts the changes of the row.
 */
return new class extends Migration
{
    private const array STATUSES = ['requested', 'confirmed', 'rejected'];

    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('qty', 14, 4);
            $table->string('status', 12)->default('requested');
            $table->string('note', 255)->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->unsignedBigInteger('production_request_id')->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('out_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('in_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'status', 'from_location_id']);
            $table->index(['to_location_id', 'status']);
        });

        $statuses = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::STATUSES));
        DB::statement("ALTER TABLE transfers ADD CONSTRAINT transfers_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT transfers_qty_check CHECK (qty > 0)');
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT transfers_ends_differ CHECK (from_location_id <> to_location_id)');
        DB::statement(
            "ALTER TABLE transfers ADD CONSTRAINT transfers_decided_when_decided CHECK ((status = 'requested' AND decided_by_user_id IS NULL AND decided_at IS NULL) OR (status <> 'requested' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
