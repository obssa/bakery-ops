<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production requests (phase-2 plan section 3, migration 15; Production, step 2.7a). A request asks the producing station to
 * make a to-stock product: by hand (`manual`), on a schedule the planner set (`schedule`) or because the product ran out
 * (`stock_out`, created by the StockOut listener). It moves along App\Modules\Production\Enums\RequestStatus and is never
 * deleted (it ends done or cancelled), so there is no deleted_at.
 *
 * - requested_qty is DECIMAL(14,4) in the product's base unit and above zero. station_id is the producing station
 *   (Catalog Routing::productionStationFor), resolved when the request is made.
 * - client_uuid (the screen's offline id) is unique per branch (ADR 0003).
 * - open_stock_out_key is 1 while a `stock_out` request is open and NULL otherwise, set and cleared by the state machine in the
 *   same write as the status. The unique key (product_item_id, open_stock_out_key) lets the database refuse a second open
 *   stock-out request for one product when two jobs race, on both engines with no partial index (NULLs never collide). The
 *   CHECK production_requests_open_key_check keeps the key to a stock_out request.
 * - linked_order_id (reserved for advance cake orders, always NULL in Phase 2) and source_alert_id (the stock_alert_states
 *   episode of an automatic request) are plain ids: Production never reads Orders and does not join Inventory's tables.
 * - Actor stamp (rule 4): created_by_user_id, acting_user_id, shift_instance_id (no foreign key until Phase 3),
 *   actor_station_id, device_id. requested_by_user_id is the person who asked: the one acting, else the account that is logged
 *   in; an automatic request carries the person whose movement caused the stock-out (ADR 0011), so no system user is needed.
 */
return new class extends Migration
{
    private const array STATUSES = ['requested', 'accepted', 'in_progress', 'pending_confirmation', 'done', 'cancelled'];

    private const array PRIORITIES = ['normal', 'high', 'urgent'];

    private const array REASONS = ['schedule', 'stock_out', 'manual'];

    public function up(): void
    {
        Schema::create('production_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('product_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('requested_qty', 14, 4);
            $table->dateTime('due_at')->nullable();
            $table->string('priority', 8)->default('normal');
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->string('reason', 12);
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('linked_order_id')->nullable();
            $table->unsignedBigInteger('source_alert_id')->nullable();
            $table->unsignedTinyInteger('open_stock_out_key')->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('done_at')->nullable();
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
            $table->unique(['product_item_id', 'open_stock_out_key'], 'production_requests_one_open_stock_out');
            $table->index(['branch_id', 'status']);
            $table->index(['station_id', 'status']);
            $table->index(['product_item_id', 'status']);
        });

        $list = static fn (array $values): string => implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
        DB::statement('ALTER TABLE production_requests ADD CONSTRAINT production_requests_status_check CHECK (status IN ('.$list(self::STATUSES).'))');
        DB::statement('ALTER TABLE production_requests ADD CONSTRAINT production_requests_priority_check CHECK (priority IN ('.$list(self::PRIORITIES).'))');
        DB::statement('ALTER TABLE production_requests ADD CONSTRAINT production_requests_reason_check CHECK (reason IN ('.$list(self::REASONS).'))');
        DB::statement('ALTER TABLE production_requests ADD CONSTRAINT production_requests_qty_check CHECK (requested_qty > 0)');
        DB::statement("ALTER TABLE production_requests ADD CONSTRAINT production_requests_open_key_check CHECK (open_stock_out_key IS NULL OR (open_stock_out_key = 1 AND reason = 'stock_out'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('production_requests');
    }
};
