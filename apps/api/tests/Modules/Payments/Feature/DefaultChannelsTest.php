<?php

declare(strict_types=1);

use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Events\BranchCreated;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * The eight default payment channels (phase-1 plan section 3 migration 7 and section 5): every branch has them, the
 * existing ones from the migration (the live database already has a branch), a new one from the BranchCreated listener
 * (Payments' EnsureDefaultChannels). Both are idempotent and never undo what the admin changed.
 */

const DEFAULT_CODES = ['cash', 'telebirr', 'cbe_birr', 'cbe_bank', 'awash', 'dashen', 'abyssinia', 'coop'];

function paymentChannelMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_21_110100_create_payment_channels_table.php');

    return $migration;
}

/** @return list<string> */
function paymentCodesOf(int $branchId, bool $withTrashed = false): array
{
    $query = PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('sort');
    if (! $withTrashed) {
        $query->whereNull('deleted_at');
    }

    return $query->pluck('code')->all();
}

it('gives a new branch the eight channels through BranchCreated', function () {
    $seen = [];
    Event::listen(BranchCreated::class, function (BranchCreated $e) use (&$seen): void {
        $seen[] = $e->payload();
    });

    $branch = Branch::query()->create(['name' => 'Bole']);

    expect($seen)->toBe([['branch_id' => $branch->id]])
        ->and(paymentCodesOf($branch->id))->toBe(DEFAULT_CODES);
    $cash = PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', 'cash')->sole();
    expect($cash->name_en)->toBe('Cash')
        ->and($cash->name_am)->toMatch('/\p{Ethiopic}/u')
        ->and($cash->is_enabled)->toBeTrue()
        ->and($cash->requires_photo)->toBeFalse()
        ->and($cash->requires_reference)->toBeFalse()
        ->and($cash->sort)->toBe(1)
        ->and($cash->created_by_user_id)->toBeNull();
    $coop = PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', 'coop')->sole();
    expect($coop->requires_photo)->toBeTrue()->and($coop->requires_reference)->toBeTrue()->and($coop->sort)->toBe(8);
})->group('module:payments', 'feature');

it('works for a branch made by the factory, with no branch resolved and nobody signed in', function () {
    $branch = Branch::factory()->create();

    expect(paymentCodesOf($branch->id))->toBe(DEFAULT_CODES);
})->group('module:payments', 'feature');

it('puts the defaults into branches that existed before the migration, once, without touching changes', function () {
    $old = Branch::factory()->create();
    $trashed = Branch::factory()->create();
    $trashed->delete();
    $custom = Branch::factory()->create();
    // the state of a branch that was there before the table: no rows at all (the factory's listener ran already)
    DB::table('payment_channels')->delete();
    $migration = paymentChannelMigration();

    $migration->backfill();

    foreach ([$old, $trashed, $custom] as $branch) {
        expect(paymentCodesOf($branch->id))->toBe(DEFAULT_CODES);
    }
    // an admin switched cash off and removed coop; running it again changes nothing
    PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $custom->id)->where('code', 'cash')->update(['is_enabled' => false, 'name_en' => 'Till cash']);
    PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $custom->id)->where('code', 'coop')->delete();
    $before = DB::table('payment_channels')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $migration->backfill();

    expect(DB::table('payment_channels')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before)
        ->and(paymentCodesOf($custom->id))->not->toContain('coop')
        ->and(paymentCodesOf($custom->id, true))->toContain('coop')
        ->and(DB::table('payment_channels')->count())->toBe(24);
})->group('module:payments', 'feature');

it('keeps the migration\'s list and the listener\'s list the same', function () {
    $branch = Branch::factory()->create();
    $fromListener = DB::table('payment_channels')->where('branch_id', $branch->id)->orderBy('sort')
        ->get(['code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort'])->map(fn ($r) => (array) $r)->all();
    DB::table('payment_channels')->where('branch_id', $branch->id)->delete();

    paymentChannelMigration()->backfill();

    $fromMigration = DB::table('payment_channels')->where('branch_id', $branch->id)->orderBy('sort')
        ->get(['code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort'])->map(fn ($r) => (array) $r)->all();
    expect($fromMigration)->toBe($fromListener)->and($fromMigration)->toHaveCount(8);
})->group('module:payments', 'feature');
