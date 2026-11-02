<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5.5b, phase-5 plan section 3 M5 (ADR 0020): one row per run of an ops job, written by App\Ops (backup:run, backup:drill, the
 * off-site copy) and read by the System page of step 5.5c.
 *   kind         VARCHAR(16), CHECK ops_runs_kind_check IN (backup, drill, offsite);
 *   status       VARCHAR(12), CHECK ops_runs_status_check IN (running, ok, failed, skipped): `running` while the job works, then one of
 *                the other three (a row left `running` is a run the host killed);
 *   started_at, finished_at (NULL while running);
 *   bytes        BIGINT UNSIGNED NULL, the size of the dump (gzip) a backup wrote;
 *   sha256       CHAR(64) NULL, its checksum, the one its manifest carries;
 *   path_hint    VARCHAR(191) NULL, the dump's file name relative to the backup folder, never an absolute path;
 *   detail       JSON NULL: row counts, the files snapshot, the oracle and chain summaries of a drill, the failure key; never a path, a
 *                host name, a password or a key.
 * No branch_id: one host, one database (allowlisted in scripts/check_invariants.py with that reason). Not append-only: a run moves from
 * `running` to its result. Index (kind, status, id) for "the last good backup" and "the last drill". A new table: no live row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('status', 12);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('path_hint', 191)->nullable();
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status', 'id'], 'ops_runs_kind_status_id_index');
        });
        DB::statement("ALTER TABLE ops_runs ADD CONSTRAINT ops_runs_kind_check CHECK (kind IN ('backup', 'drill', 'offsite'))");
        DB::statement("ALTER TABLE ops_runs ADD CONSTRAINT ops_runs_status_check CHECK (status IN ('running', 'ok', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_runs');
    }
};
