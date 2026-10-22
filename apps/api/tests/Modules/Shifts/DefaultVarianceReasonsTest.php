<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\VarianceReason;
use App\Modules\Shifts\Support\DefaultVarianceReasons;
use App\Shared\Events\BranchCreated;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

pest()->group('module:shifts', 'feature');

/*
 * The five default variance reasons (phase-3 plan section 3 migration 8; architecture 4.4 point 2): every branch has them, the
 * existing ones from the migration (the live database already has a branch), a new one from the BranchCreated listener
 * (Shifts' EnsureDefaultVarianceReasons, Support\DefaultVarianceReasons). Both are idempotent, safe on a populated database and
 * never undo what the admin changed or removed. The Amharic texts are a first draft for Phase 5's review with the staff.
 */

const DEFAULT_REASON_CODES = ['spoilage', 'breakage', 'miscount', 'theft_suspected', 'other'];

function varianceReasonMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_24_120100_create_variance_reasons_table.php');

    return $migration;
}

/** @return list<string> */
function reasonCodesOf(int $branchId, bool $withTrashed = false): array
{
    $query = VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('sort')->orderBy('id');
    if ($withTrashed) {
        $query->withTrashed();
    }

    return $query->pluck('code')->all();
}

it('gives a new branch the five reasons through BranchCreated, bilingual, and other requires a note', function () {
    $seen = [];
    Event::listen(BranchCreated::class, function (BranchCreated $e) use (&$seen): void {
        $seen[] = $e->payload();
    });

    $branch = Branch::query()->create(['name' => 'Bole']);

    expect($seen)->toBe([['branch_id' => $branch->id]])
        ->and(reasonCodesOf($branch->id))->toBe(DEFAULT_REASON_CODES);
    $names = VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('sort')->get();
    expect($names->pluck('name_en')->all())->toBe(['Spoilage', 'Breakage', 'Miscount', 'Theft suspected', 'Other'])
        ->and($names->pluck('name_am')->all())->toBe(['መበላሸት', 'መሰበር', 'የቆጠራ ስህተት', 'የስርቆት ጥርጣሬ', 'ሌላ'])
        ->and($names->pluck('requires_note')->all())->toBe([false, false, false, false, true])
        ->and($names->pluck('is_active')->unique()->all())->toBe([true])
        ->and($names->pluck('sort')->all())->toBe([1, 2, 3, 4, 5])
        ->and($names->pluck('created_by_user_id')->unique()->all())->toBe([null]);
});

it('works for a branch made by the factory, with no branch resolved and nobody signed in', function () {
    $branch = Branch::factory()->create();

    expect(reasonCodesOf($branch->id))->toBe(DEFAULT_REASON_CODES);
});

it('adds only what is missing, once, and leaves an edited, switched-off or removed reason as it is', function () {
    $branch = Branch::factory()->create();
    VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', 'spoilage')->update(['name_en' => 'Went off', 'is_active' => false]);
    VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', 'breakage')->first()?->delete();
    DB::table('variance_reasons')->where('branch_id', $branch->id)->where('code', 'miscount')->delete(); // gone for good: it comes back
    $before = DB::table('variance_reasons')->where('branch_id', $branch->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $written = DefaultVarianceReasons::ensureFor($branch->id);

    expect($written)->toBe(1)
        ->and(reasonCodesOf($branch->id, true))->toContain('miscount', 'breakage');
    $after = DB::table('variance_reasons')->where('branch_id', $branch->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    expect(array_slice($after, 0, count($before)))->toBe($before);
    $edited = VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', 'spoilage')->sole();
    expect($edited->name_en)->toBe('Went off')->and($edited->is_active)->toBeFalse();
    expect(VarianceReason::query()->withoutGlobalScopes()->withTrashed()->where('branch_id', $branch->id)->where('code', 'breakage')->sole()->trashed())->toBeTrue();

    expect(DefaultVarianceReasons::ensureFor($branch->id))->toBe(0);
});

it('does not add a second set when the event fires twice', function () {
    $branch = Branch::factory()->create();

    event(new BranchCreated($branch->id));

    expect(reasonCodesOf($branch->id))->toBe(DEFAULT_REASON_CODES)
        ->and(DB::table('variance_reasons')->where('branch_id', $branch->id)->count())->toBe(5);
});

it('puts the defaults into branches that existed before the migration, once, without touching changes', function () {
    $old = Branch::factory()->create();
    $trashed = Branch::factory()->create();
    $trashed->delete();
    $custom = Branch::factory()->create();
    // the state of a branch that was there before the table: no rows at all (the factory's listener ran already)
    DB::table('variance_reasons')->delete();
    $migration = varianceReasonMigration();

    $migration->backfill();

    foreach ([$old, $trashed, $custom] as $branch) {
        expect(reasonCodesOf($branch->id))->toBe(DEFAULT_REASON_CODES);
    }
    // an admin renamed one and removed another; running it again changes nothing
    VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $custom->id)->where('code', 'other')->update(['name_en' => 'Something else']);
    VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $custom->id)->where('code', 'theft_suspected')->first()?->delete();
    $before = DB::table('variance_reasons')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $migration->backfill();

    expect(DB::table('variance_reasons')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before)
        ->and(reasonCodesOf($custom->id))->not->toContain('theft_suspected')
        ->and(reasonCodesOf($custom->id, true))->toContain('theft_suspected')
        ->and(DB::table('variance_reasons')->count())->toBe(15);
});

it('keeps the migration\'s list and the listener\'s list the same', function () {
    $branch = Branch::factory()->create();
    $columns = ['code', 'name_en', 'name_am', 'requires_note', 'is_active', 'sort'];
    $fromListener = DB::table('variance_reasons')->where('branch_id', $branch->id)->orderBy('sort')->get($columns)->map(fn ($r) => (array) $r)->all();
    DB::table('variance_reasons')->where('branch_id', $branch->id)->delete();

    varianceReasonMigration()->backfill();

    $fromMigration = DB::table('variance_reasons')->where('branch_id', $branch->id)->orderBy('sort')->get($columns)->map(fn ($r) => (array) $r)->all();
    expect($fromMigration)->toBe($fromListener)->and($fromMigration)->toHaveCount(5);
});
