<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5.1a, phase-5 plan section 3 M1 (ADR 0017): devices learns what it needs for enrolment and enforcement.
 *   kind          VARCHAR(16) NOT NULL DEFAULT 'tablet', CHECK devices_kind_check IN (tablet, till, kds, bridge) (App.Modules.Identity.Enums.DeviceKind);
 *   station_id    the tablet's home station, informative only (enforcement never reads it), FK stations, NULL;
 *   last_seen_at  written by EnsureEnrolledDevice at most once a minute per device, NULL until the flag is on and the device is seen;
 *   client_uuid   of an enrolment made through POST /devices, UNIQUE (branch_id, client_uuid) (ADR 0003), NULL for the rows of Phase 0;
 *   token_hash    sha256 of a print bridge's token (hex, 64), UNIQUE; CHECK devices_token_only_for_bridge (kind = 'bridge' OR token_hash IS NULL);
 *   revoked_by    who revoked it, FK users, NULL.
 * On the live database every existing row (a handful of pads enrolled since Phase 0) becomes a tablet through the column default and
 * keeps NULL everywhere else, so both CHECKs hold on it (kind 'tablet' is in the list; its token_hash is NULL) and both UNIQUEs too
 * (several NULLs never collide on MariaDB 10.11 or PostgreSQL). No row is updated; ADD COLUMN with a default is all. Portable DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->uuid('client_uuid')->nullable()->after('branch_id');
            $table->string('kind', 16)->default('tablet')->after('name');
            $table->foreignId('station_id')->nullable()->after('kind')->constrained('stations')->nullOnDelete();
            $table->char('token_hash', 64)->nullable()->after('fingerprint');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable()->after('revoked_by');
            $table->unique(['branch_id', 'client_uuid'], 'devices_branch_client_uuid_unique');
            $table->unique('token_hash', 'devices_token_hash_unique');
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_kind_check CHECK (kind IN ('tablet', 'till', 'kds', 'bridge'))");
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_token_only_for_bridge CHECK (kind = 'bridge' OR token_hash IS NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE devices DROP CONSTRAINT devices_token_only_for_bridge');
        DB::statement('ALTER TABLE devices DROP CONSTRAINT devices_kind_check');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique('devices_token_hash_unique');
            $table->dropUnique('devices_branch_client_uuid_unique');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropConstrainedForeignId('station_id');
            $table->dropColumn(['client_uuid', 'kind', 'token_hash', 'last_seen_at']);
        });
    }
};
