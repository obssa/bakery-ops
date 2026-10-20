<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines (domain-model.md, Orders; docs/phases/phase-1-plan.md section 3, migration 4; ADR 0008). Each line has
 * its own status (App\Modules\Orders\Enums\LineStatus) so the bar can be ready while the kitchen cooks; never deleted
 * (rejected or voided by status), so there is no deleted_at.
 *
 * - qty is DECIMAL(14,4) in units of the product (must be positive); unit_price_snapshot is Prices::at(sent_at),
 *   modifier_price_snapshot the sum of the modifiers' price deltas per unit, line_total = qty x (unit + modifiers),
 *   all DECIMAL(12,2) (ADR 0004); a line written on an open order carries the current price until the send
 *   re-snapshots it.
 * - recipe_version: the active recipe at send (to_order products only). station_id: where the line routes (null for
 *   a display-case line); location_id: where its stock is reserved (Inventory Locations::forProduct).
 * - reservation_ref: one reservation per line, a UUIDv5 of the line's client UUID (ADR 0008), unique, so an offline
 *   replay can only reach the same reservation; client_uuid is unique per branch (ADR 0003).
 * - accepted / prepared (ready) / served / rejected by and at: who moved the line, and when; reject_reason is one of
 *   the fixed list (RejectReason), reject_note the free text `other` needs.
 * - Actor stamp (rule 4): created_by_user_id, acting_user_id, device_id; shift_instance_id without a foreign key until
 *   Phase 3. version counts the changes of the row: the clients merge the live feeds by it (ADR 0009).
 * Indexes: the lines of an order, and the KDS rail of a station by status.
 */
return new class extends Migration
{
    private const array STATUSES = ['queued', 'accepted', 'ready', 'served', 'rejected', 'voided'];

    private const array REJECT_REASONS = ['ingredient_spoiled', 'equipment_down', 'out_of_stock', 'other'];

    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 14, 4);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('modifier_price_snapshot', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->unsignedInteger('recipe_version')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->uuid('reservation_ref');
            $table->string('status', 20)->default('queued');
            $table->string('notes', 500)->nullable();

            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('ready_at')->nullable();
            $table->foreignId('served_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('served_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->string('reject_reason', 32)->nullable();
            $table->string('reject_note', 500)->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid']);
            $table->unique('reservation_ref');
            $table->index('order_id');
            $table->index(['branch_id', 'station_id', 'status']);
        });

        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_status_check CHECK (status IN ('.self::list(self::STATUSES).'))');
        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_reject_reason_check CHECK (reject_reason IN ('.self::list(self::REJECT_REASONS).'))');
        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_qty_positive CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }

    /**
     * @param  list<string>  $values
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }
};
