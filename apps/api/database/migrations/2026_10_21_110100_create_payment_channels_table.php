<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ways a branch takes money (domain-model.md, Payments; docs/phases/phase-1-plan.md section 3, migration 7: the
 * final schema). One row per branch and code: cash, telebirr, cbe_birr, cbe_bank, awash, dashen, abyssinia, coop.
 * Names are bilingual (rule 9). requires_photo / requires_reference say what a payment on the channel must come with:
 * the digital channels need the receipt photo and the bank's reference (Phase 4 builds the photo upload; until then
 * Payments refuses them). A removed channel is soft-deleted: it stays in the history of the payments made on it.
 *
 * The migration also puts the eight defaults into every branch that exists (the live database already has one; soft
 * deleted branches too, harmlessly): INSERT IGNORE on the unique (branch_id, code), so it is idempotent and never
 * resurrects or changes a row an admin edited or removed. New branches get the same rows from the BranchCreated
 * listener (Payments' EnsureDefaultChannels, Support\DefaultChannels); the list is repeated here on purpose, because a
 * migration must not depend on application classes that change later (tests/Modules/Payments/Feature/
 * DefaultChannelsTest.php keeps the two lists equal).
 */
return new class extends Migration
{
    /** @var list<array{code: string, name_en: string, name_am: string, requires_photo: bool, requires_reference: bool}> */
    private const array DEFAULTS = [
        ['code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'requires_photo' => false, 'requires_reference' => false],
        ['code' => 'telebirr', 'name_en' => 'Telebirr', 'name_am' => 'ቴሌብር', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'cbe_birr', 'name_en' => 'CBE Birr', 'name_am' => 'ሲቢኢ ብር', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'cbe_bank', 'name_en' => 'CBE bank transfer', 'name_am' => 'የንግድ ባንክ ዝውውር', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'awash', 'name_en' => 'Awash Bank', 'name_am' => 'አዋሽ ባንክ', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'dashen', 'name_en' => 'Dashen Bank', 'name_am' => 'ዳሽን ባንክ', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'abyssinia', 'name_en' => 'Bank of Abyssinia', 'name_am' => 'የአቢሲኒያ ባንክ', 'requires_photo' => true, 'requires_reference' => true],
        ['code' => 'coop', 'name_en' => 'Cooperative Bank of Oromia', 'name_am' => 'የኦሮሚያ ህብረት ሥራ ባንክ', 'requires_photo' => true, 'requires_reference' => true],
    ];

    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->smallInteger('sort')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
        });

        $this->backfill();
    }

    /** The eight defaults for every branch that has none of them yet; a row that exists (edited, removed) is left alone. */
    public function backfill(): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        foreach (DB::table('branches')->orderBy('id')->pluck('id') as $branchId) {
            $rows = [];
            foreach (self::DEFAULTS as $i => $channel) {
                $rows[] = $channel + ['branch_id' => (int) $branchId, 'is_enabled' => true, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('payment_channels')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
