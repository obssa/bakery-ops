<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The counting rules (Ramin 2026-11-12, ADR 0026): three switches per station the admin turns on and off in Admin > System > Counting rules
 * (PUT /counting-rules, shifts.manage).
 *
 * - count_opening: a shift starts with an opening count (on, as before) or opens at once, carried from the last closing count (off).
 * - count_cosign: a handed-in count waits for a second person's co-signature. Until now this was `requires_overseer`, which also makes a
 *   finished production batch wait for an overseer; the two are separate from here on, so switching co-sign never changes the production
 *   board. It starts as each station's requires_overseer, so nothing changes on the day this runs.
 * - count_approval: a count whose figures differ waits for a manager's decision (on, as before), or is approved by the rule (off).
 *
 * Every existing row keeps today's behaviour. Reversible: the columns go, and requires_overseer is still there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stations', 'count_opening')) {
            Schema::table('stations', function (Blueprint $table): void {
                $table->boolean('count_opening')->default(true)->after('tracks_shifts');
                $table->boolean('count_cosign')->default(false)->after('count_opening');
                $table->boolean('count_approval')->default(true)->after('count_cosign');
            });
            DB::table('stations')->update(['count_cosign' => DB::raw('requires_overseer')]);
        }
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn(['count_opening', 'count_cosign', 'count_approval']);
        });
    }
};
