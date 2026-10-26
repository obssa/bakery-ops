<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * analytics_days (phase-4 plan section 3, M12; Analytics, step 4.4a; ADR 0014): one row per branch and LOCAL day that Rebuild::day has
 * built. It is the lock target of a rebuild (created by an upsert that changes nothing, then SELECT ... FOR UPDATE: never a lock on a
 * row that may not exist, traps.md "First-row race") and the freshness feed of the dashboards (step 4.4b).
 *
 * - version counts the rebuilds of the day; rebuilt_at is when the last one committed (microseconds); trigger says why it ran
 *   (shift_locked, nightly, manual, touch, seed: CHECK analytics_days_trigger_check); duration_ms how long it took.
 * - row_counts is the number of rows the rebuild wrote per summary table (JSON object, table => count).
 * - fingerprint is the sha256 of the canonical form of every summary row of the day (Build\Fingerprint), so two rebuilds compare with
 *   one string; changed is whether it differs from the run before (the first run is a change). It is never NULL once a rebuild has
 *   committed: the upsert's placeholder row (version 0, no fingerprint) only exists inside the rebuild's own transaction.
 * Derived data: no client_uuid, no actor stamp, no deleted_at; a row may be deleted physically (a branch's history rebuilt anew).
 * New and empty when it is deployed, so it is safe on the live database.
 */
return new class extends Migration
{
    private const array TRIGGERS = ['shift_locked', 'nightly', 'manual', 'touch', 'seed'];

    public function up(): void
    {
        Schema::create('analytics_days', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('day');
            $table->unsignedInteger('version')->default(0);
            $table->dateTime('rebuilt_at', 6);
            $table->string('trigger', 16);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('row_counts');
            $table->char('fingerprint', 64)->nullable();
            $table->boolean('changed')->default(false);

            $table->primary(['branch_id', 'day']);
        });

        $trigger = DB::getQueryGrammar()->wrap('trigger');
        DB::statement("ALTER TABLE analytics_days ADD CONSTRAINT analytics_days_trigger_check CHECK ({$trigger} IN (".self::list(self::TRIGGERS).'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_days');
    }

    /**
     * @param  list<string>  $values
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }
};
