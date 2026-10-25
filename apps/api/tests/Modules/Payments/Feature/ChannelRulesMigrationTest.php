<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\Channels;
use App\Modules\Payments\Contracts\ChannelView;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * M3 extend_payment_channels_for_rules (phase-4 plan section 3, step 4.3a) runs on the live MariaDB the moment it merges, where every
 * branch already has the eight Phase 1 channels (some of them edited by hand). It only adds nullable columns, so every existing row
 * keeps its meaning: no client_uuid (the defaults were never created by a client), no reference pattern (any non-empty reference
 * passes, as today), no hints. requires_photo and requires_reference are not touched. Nothing is backfilled.
 */

const M3_COLUMNS = ['client_uuid', 'reference_pattern', 'reference_hint_en', 'reference_hint_am', 'photo_hint_en', 'photo_hint_am'];

const PHASE_1_CHANNEL_COLUMNS = ['id', 'branch_id', 'code', 'name_en', 'name_am', 'is_enabled', 'requires_photo', 'requires_reference', 'sort', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

function channelRulesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_31_100100_extend_payment_channels_for_rules.php');

    return $migration;
}

/** @return list<array<string, mixed>> */
function phaseOneChannelRows(): array
{
    return DB::table('payment_channels')->orderBy('id')->get(PHASE_1_CHANNEL_COLUMNS)->map(fn ($r) => (array) $r)->all();
}

/** @return array<string, object> column name => information_schema row */
function channelColumns(): array
{
    $rows = DB::select(
        'select column_name as column_name, is_nullable as is_nullable, data_type as data_type, character_maximum_length as max_length, column_default as column_default
           from information_schema.columns where table_schema = ? and table_name = ?',
        [dbSchema(), 'payment_channels'],
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row->column_name] = $row;
    }

    return $out;
}

it('adds nullable columns with no default, and a unique client_uuid per branch', function () {
    $columns = channelColumns();

    foreach (M3_COLUMNS as $name) {
        expect($columns)->toHaveKey($name);
        expect($columns[$name]->is_nullable)->toBe('YES', $name)
            ->and(in_array($columns[$name]->column_default, [null, 'NULL'], true))->toBeTrue("{$name} has no default");
    }
    foreach (['reference_pattern', 'reference_hint_en', 'reference_hint_am', 'photo_hint_en', 'photo_hint_am'] as $name) {
        expect(strtolower((string) $columns[$name]->data_type))->toBe('varchar', $name)
            ->and((int) $columns[$name]->max_length)->toBe(120, $name);
    }
    expect(Schema::hasIndex('payment_channels', ['branch_id', 'client_uuid'], 'unique'))->toBeTrue();
})->group('module:payments', 'feature');

it('leaves the defaults of a new branch as Phase 1 wrote them, with no rule, hint or client_uuid', function () {
    $branch = Branch::factory()->create();

    $rows = DB::table('payment_channels')->where('branch_id', $branch->id)->orderBy('sort')->get();

    expect($rows)->toHaveCount(8);
    foreach ($rows as $row) {
        foreach (M3_COLUMNS as $name) {
            expect($row->{$name})->toBeNull("{$row->code}.{$name}");
        }
    }
})->group('module:payments', 'feature');

it('runs on a database with the Phase 1 channels and changes none of them; a second run is a no-op', function () {
    // DDL commits the transaction RefreshDatabase wraps the test in (MariaDB, MySQL); RefreshDatabase sees that at the end and migrates
    // afresh before the next test, so what this test commits does not leak. It is the last test of the file on purpose.
    $migration = channelRulesMigration();
    $edited = Branch::factory()->create();
    $plain = Branch::factory()->create();
    $id = fn (Branch $b, string $code): int => (int) DB::table('payment_channels')->where('branch_id', $b->id)->where('code', $code)->value('id');
    // the state before M3: an admin renamed cash, switched awash off, removed coop and asked dashen for a reference only
    $migration->down();
    expect(Schema::hasColumn('payment_channels', 'reference_pattern'))->toBeFalse()
        ->and(Schema::hasColumn('payment_channels', 'client_uuid'))->toBeFalse();
    DB::table('payment_channels')->where('id', $id($edited, 'cash'))->update(['name_en' => 'Till cash']);
    DB::table('payment_channels')->where('id', $id($edited, 'awash'))->update(['is_enabled' => false]);
    DB::table('payment_channels')->where('id', $id($edited, 'coop'))->update(['deleted_at' => now()->utc()->format('Y-m-d H:i:s')]);
    DB::table('payment_channels')->where('id', $id($edited, 'dashen'))->update(['requires_photo' => false]);
    // and a branch created while Phase 1 code was live: its eight defaults written by the Phase 1 listener into the Phase 1 schema
    $late = Branch::factory()->create();
    $before = phaseOneChannelRows();
    expect($before)->toHaveCount(24);

    $migration->up();

    expect(phaseOneChannelRows())->toBe($before);
    foreach (DB::table('payment_channels')->get() as $row) {
        foreach (M3_COLUMNS as $name) {
            expect($row->{$name})->toBeNull("{$row->code}.{$name}");
        }
    }
    foreach (M3_COLUMNS as $name) {
        expect(Schema::hasColumn('payment_channels', $name))->toBeTrue($name);
    }
    // what the till reads is what it read before
    actInBranch($edited);
    $enabled = app(Channels::class)->enabled();
    expect(array_map(fn (ChannelView $c): string => $c->code, $enabled))->toBe(['cash', 'telebirr', 'cbe_birr', 'cbe_bank', 'dashen', 'abyssinia'])
        ->and($enabled[0]->nameEn)->toBe('Till cash')
        ->and($enabled[0]->acceptedNow())->toBeTrue()
        ->and($enabled[1]->acceptedNow())->toBeFalse()
        ->and($enabled[4]->acceptedNow())->toBeTrue()
        ->and($enabled[4]->requiresReference)->toBeTrue()
        ->and($enabled[4]->referencePattern)->toBeNull();
    actInBranch($late);
    expect(PaymentChannel::query()->orderBy('sort')->pluck('code')->all())->toBe(['cash', 'telebirr', 'cbe_birr', 'cbe_bank', 'awash', 'dashen', 'abyssinia', 'coop']);
    actInBranch($plain);
    expect(app(Channels::class)->enabled())->toHaveCount(8);

    $columns = channelColumns();
    $migration->up();

    expect(channelColumns())->toEqual($columns)
        ->and(phaseOneChannelRows())->toBe($before);
})->group('module:payments', 'feature');
