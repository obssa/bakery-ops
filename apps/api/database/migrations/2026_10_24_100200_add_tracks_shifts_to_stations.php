<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3, step 3.1a (plan section 3, migration 2): "does this station run shifts at all?" The ledger check, the counts
 * and the roster apply only where it is true. Every station starts true; the one backfill is the warehouse, whose stock
 * the admin and manager adjust by hand (a station with code `store` gets false, here and, for a store created later, in
 * StationsController::store). Reversible by PATCH /stations/{station}. No other existing row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stations', 'tracks_shifts')) {
            Schema::table('stations', function (Blueprint $table): void {
                $table->boolean('tracks_shifts')->default(true)->after('requires_overseer');
            });
        }

        DB::table('stations')->where('code', 'store')->update(['tracks_shifts' => false]);
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn('tracks_shifts');
        });
    }
};
