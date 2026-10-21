<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases (phase-2 plan section 3, migration 4; Inventory, step 2.2a). A purchase is a draft until confirmed: confirm
 * (step 2.2b) writes one purchase_in movement per line at location_id (the store) through the Ledger and feeds the moving
 * average (item_costs). Never deleted: it ends confirmed or cancelled by status (App\Modules\Inventory\Enums\PurchaseStatus),
 * so there is no deleted_at.
 *
 * - client_uuid (the screen's offline id) is unique per branch (ADR 0003): a replay with a lost key finds the same row.
 * - purchase_date is the branch's local date of the invoice; total is the sum of the lines' line_total, kept in step by
 *   the draft actions. Money is DECIMAL(12,2) in `currency` (ADR 0004).
 * - CHECKs: status is the machine's list, total >= 0, and confirmed_at is set exactly when the status is confirmed.
 * - Actor stamp (rule 4): created_by_user_id, acting_user_id, shift_instance_id (no foreign key until Phase 3),
 *   actor_station_id, device_id; confirmed_by_user_id is who confirmed. version counts the changes of the row.
 */
return new class extends Migration
{
    private const array STATUSES = ['draft', 'confirmed', 'cancelled'];

    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('invoice_no', 64)->nullable();
            $table->date('purchase_date');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 12)->default('draft');
            $table->char('currency', 3)->default('ETB');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'status', 'purchase_date']);
            $table->index(['supplier_id', 'invoice_no']);
        });

        $statuses = implode(', ', array_map(static fn (string $status): string => "'{$status}'", self::STATUSES));
        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_total_check CHECK (total >= 0)');
        DB::statement(
            "ALTER TABLE purchases ADD CONSTRAINT purchases_confirmed_when_confirmed CHECK ((status = 'confirmed' AND confirmed_at IS NOT NULL) OR (status <> 'confirmed' AND confirmed_at IS NULL))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
