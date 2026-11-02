<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live settings (phase-5 plan, "Go-live decisions (2026-11-02)"): "the admin and the shift manager get the call, or anyone the admin
 * assigns to it".
 *   on_call  BOOLEAN NOT NULL DEFAULT false: the "On call" switch of Admin > People (PATCH /users/{id}, people.manage, audited as
 *            user.update). A person on call hears the ops alerts (NotifyOps: backup failed, backup stale, drill failed) and the failed
 *            prints (NotifyPrintFailed) beside the people those already reach (Identity\Contracts\Recipients::onCall).
 * The live table holds real people: ADD COLUMN with a default fills every row with false, so nobody is on call until the admin says so
 * and no row is updated. Portable DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('on_call')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('on_call');
        });
    }
};
