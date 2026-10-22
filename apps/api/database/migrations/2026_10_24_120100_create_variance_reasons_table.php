<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variance reasons (phase-3 plan section 3, migration 8; Shifts, step 3.3a): why a counted figure differs from the ledger.
 * Bilingual (rule 9), per branch, configuration: soft-deleted and audited, never part of a locked count's hash by id alone (the
 * reason's code and names are copied into the payload in step 3.4). `requires_note` makes the counter say more (the default
 * `other`); `is_active` switches a reason off without losing the history of the lines that used it.
 *
 * The migration also gives every branch that exists (the live database already has one; soft-deleted branches too, harmlessly)
 * the five defaults of architecture section 4.4 point 2: INSERT IGNORE on the unique (branch_id, code), so it is idempotent, safe
 * on a populated database and never resurrects or changes a row an admin edited or removed. New branches get the same rows from
 * the BranchCreated listener (Shifts' EnsureDefaultVarianceReasons, Support\DefaultVarianceReasons); the list is repeated here on
 * purpose, because a migration must not depend on application classes that change later (DefaultVarianceReasonsTest keeps the two
 * lists equal). The Amharic texts are a first draft for Phase 5's review with the staff.
 */
return new class extends Migration
{
    /** @var list<array{code: string, name_en: string, name_am: string, requires_note: bool}> */
    private const array DEFAULTS = [
        ['code' => 'spoilage', 'name_en' => 'Spoilage', 'name_am' => 'መበላሸት', 'requires_note' => false],
        ['code' => 'breakage', 'name_en' => 'Breakage', 'name_am' => 'መሰበር', 'requires_note' => false],
        ['code' => 'miscount', 'name_en' => 'Miscount', 'name_am' => 'የቆጠራ ስህተት', 'requires_note' => false],
        ['code' => 'theft_suspected', 'name_en' => 'Theft suspected', 'name_am' => 'የስርቆት ጥርጣሬ', 'requires_note' => false],
        ['code' => 'other', 'name_en' => 'Other', 'name_am' => 'ሌላ', 'requires_note' => true],
    ];

    public function up(): void
    {
        Schema::create('variance_reasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_en', 80);
            $table->string('name_am', 80);
            $table->boolean('requires_note')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_instance_id')->nullable()->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active', 'sort']);
        });

        $this->backfill();
    }

    /** The five defaults for every branch that lacks them; a row that exists (edited, switched off, removed) is left alone. */
    public function backfill(): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        foreach (DB::table('branches')->orderBy('id')->pluck('id') as $branchId) {
            $rows = [];
            foreach (self::DEFAULTS as $i => $reason) {
                $rows[] = $reason + ['branch_id' => (int) $branchId, 'is_active' => true, 'sort' => $i + 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('variance_reasons')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('variance_reasons');
    }
};
