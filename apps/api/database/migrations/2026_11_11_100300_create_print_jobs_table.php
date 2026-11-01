<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5.3a, phase-5 plan section 3 M3 (ADR 0019): print_jobs, the receipts waiting for the print bridge.
 *   client_uuid           of the till's request, UNIQUE (branch_id, client_uuid) (ADR 0003): a retried request finds the same job;
 *   kind                  VARCHAR(16) CHECK print_jobs_kind_check IN (receipt);
 *   copies                1 to 3 (the request's rule), DEFAULT 1;
 *   status                VARCHAR(16) CHECK print_jobs_status_check IN (queued, claimed, printed, failed, expired), DEFAULT queued;
 *   attempts              how often a bridge claimed it (at most print.max_claims, 3), DEFAULT 0;
 *   claimed_by_device_id  the bridge that holds it or held it last, FK devices, NULL;
 *   claimed_at, printed_at, failed_at, failure_key (paper_out, offline, cover_open, unknown, or unacknowledged from the sweep);
 *   the actor stamp of the person who asked (created_by_user_id, acting_user_id, shift_instance_id, station_id, device_id);
 *   timestamps and soft deletes; index (branch_id, status, id), the bridge's "oldest queued job" read.
 * Not append-only: a job is a work item that changes state; its history is the audit log (print_job.create, claim, printed, failed,
 * requeue, expire). A new table: nothing on the live host is touched. Portable DDL; the CHECKs hold on MariaDB and PostgreSQL alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 16)->default('receipt');
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('claimed_by_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_key', 64)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'client_uuid'], 'print_jobs_branch_client_uuid_unique');
            $table->index(['branch_id', 'status', 'id'], 'print_jobs_branch_status_id_index');
            $table->index(['branch_id', 'order_id', 'id'], 'print_jobs_branch_order_id_index');
        });

        DB::statement("ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_kind_check CHECK (kind IN ('receipt'))");
        DB::statement("ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_status_check CHECK (status IN ('queued', 'claimed', 'printed', 'failed', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
