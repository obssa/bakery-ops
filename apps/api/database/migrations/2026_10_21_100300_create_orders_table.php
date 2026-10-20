<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders (domain-model.md, Orders; docs/phases/phase-1-plan.md section 3, migration 3). Never deleted: an order ends
 * closed, cancelled or voided by status (App\Modules\Orders\Enums\OrderStatus), so there is no deleted_at.
 *
 * - client_uuid (the pad's offline id) is unique per branch (ADR 0003): a replay with a lost key finds the same order.
 * - order_no and business_date are given together by SendOrder from order_counters (the branch's local day), so an
 *   open cart has neither; CHECK orders_numbered_on_a_business_date keeps the pair whole, the unique key keeps a
 *   number once per branch and day.
 * - owner_user_id: whose order it is (the acting waiter, else the creator), the cursor of the pad's own feed.
 * - Actor stamp (rule 4): created_by_user_id (the account signed in, the shared pad's), acting_user_id (the waiter
 *   behind the short code), station_id, device_id; shift_instance_id has no foreign key until Shifts exists (Phase 3,
 *   dependency-graph.md cycle 1). voided_by_user_id asked for the void, void_approved_by_user_id gave the PIN.
 * - Money is DECIMAL(12,2) in `currency` (ADR 0004); discount and tax are 0 in Phase 1; paid_total is kept by the
 *   PaymentRecorded listener (step 1.4). settled_at: paid in full before the last line was served.
 * - version counts the changes of the row, for the clients' merge of the live feeds (ADR 0009).
 * type, status and created_via are VARCHAR with named CHECK constraints (the enums' lists, MariaDB 10.11 and
 * PostgreSQL alike); times are DATETIME in UTC.
 */
return new class extends Migration
{
    private const array TYPES = ['dine_in', 'takeaway', 'delivery', 'advance'];

    private const array STATUSES = ['open', 'sent', 'in_progress', 'ready', 'served', 'paid', 'closed', 'cancelled', 'voided'];

    private const array CREATED_VIA = ['waiter_pad', 'till', 'admin'];

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->unsignedInteger('order_no')->nullable();
            $table->date('business_date')->nullable();
            $table->string('type', 20);
            $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->restrictOnDelete();
            $table->string('table_code', 16)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('created_via', 20);

            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->char('currency', 3)->default('ETB');
            $table->text('notes')->nullable();

            $table->dateTime('sent_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('void_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('due_at')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'client_uuid']);
            $table->unique(['branch_id', 'business_date', 'order_no']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'owner_user_id', 'status']);
            $table->index(['dining_table_id', 'status']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_type_check CHECK (type IN ('.self::list(self::TYPES).'))');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('.self::list(self::STATUSES).'))');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_created_via_check CHECK (created_via IN ('.self::list(self::CREATED_VIA).'))');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_numbered_on_a_business_date CHECK ((order_no IS NULL AND business_date IS NULL) OR (order_no IS NOT NULL AND business_date IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }

    /**
     * @param  list<string>  $values
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }
};
