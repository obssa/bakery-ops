<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared file store's index (phase-4 plan section 3, M1; step 4.2a): one row per stored object, written by App\Shared\Files\Files.
 * Safe on the live MariaDB the moment it merges: a new, empty table nothing reads until the upload endpoint (step 4.2b) exists.
 *
 * - key: receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg, unique; the original file name is never stored. disk: where the object lives
 *   (`files` locally, `files_s3` in a bucket), so a half-moved bucket still resolves. sha256 is of the stored (re-encoded) bytes.
 * - purpose: receipt (a payment's photo) or refund_proof (a refund's), CHECKed.
 * - Actor stamp (rule 4): uploaded_by_user_id, acting_user_id, shift_instance_id, station_id, device_id. Nullable: a system caller
 *   (the doctor, a seeder) has no actor.
 * - attached_type / attached_id / attached_at: what claimed the file (`payment`, `payment_refund`); null while it is an upload nobody
 *   used, which the orphan purge (24 h, step 4.2b) removes. Not append-only; soft-deleted, the object goes with the purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->string('purpose', 24);
            $table->string('disk', 24);
            $table->string('key', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_instance_id')->nullable()->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->string('attached_type', 24)->nullable();
            $table->unsignedBigInteger('attached_id')->nullable();
            $table->dateTime('attached_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('key');
            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'sha256']);
            $table->index(['attached_type', 'attached_id']);
            $table->index(['branch_id', 'attached_at', 'created_at']);
        });

        DB::statement("ALTER TABLE stored_files ADD CONSTRAINT stored_files_purpose_check CHECK (purpose IN ('receipt', 'refund_proof'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
