<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChainFixture;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

uses(CommitsForConcurrency::class);

/*
 * Locks::lockApproved() and the end of migration 12 (phase-3 plan section 6, item 4): the deploys between 3.3b and 3.4 left counts
 * `approved` (no variance at submit, or decided) with no hash. The migration locks each of them in its own transaction, oldest decision
 * first per place, and may be run again: a second run finds nothing. Rows in any other status are left alone. The live database has
 * such counts from the @live runs, so the same code must be safe on a populated database.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->place = $this->f->place($this->f->kitchen);
    $this->bar = $this->f->place($this->f->kitchen, App\Modules\Inventory\Enums\LocationType::Bar);
    $this->flour = $this->f->ingredient();
    $this->line = fn (): array => [['item' => $this->flour, 'counted' => '9500', 'expected' => '10000', 'reason' => 'spoilage']];
    $at = fn (string $time): array => ['decided_at' => CarbonImmutable::parse("2026-11-0{$time}:00:00", 'UTC'), 'submitted_at' => CarbonImmutable::parse("2026-11-0{$time}:00:00", 'UTC')->subHour()];
    $this->at = $at;
});

it('locks every approved count, oldest decision first within a place, one chain per place', function () {
    $late = $this->chain->approved($this->place, ($this->line)(), ($this->at)('3 10'));
    $early = $this->chain->approved($this->place, ($this->line)(), ($this->at)('1 10'));
    $middle = $this->chain->approved($this->place, [], ($this->at)('2 10'));
    $other = $this->chain->approved($this->bar, [], ($this->at)('2 11'));

    $locked = app(Locks::class)->lockApproved();

    expect($locked)->toBe(4);
    $seq = fn (StockCount $c): ?int => StockCount::query()->findOrFail($c->id)->chain_seq;
    expect($seq($early))->toBe(1)->and($seq($middle))->toBe(2)->and($seq($late))->toBe(3)->and($seq($other))->toBe(1);
    expect(StockCount::query()->findOrFail($early->id)->status)->toBe(CountStatus::Locked)
        ->and(app(HashChain::class)->verify($this->place->id)->toArray())->toMatchArray(['ok' => true, 'checked' => 3])
        ->and(app(HashChain::class)->verify($this->bar->id)->toArray())->toMatchArray(['ok' => true, 'checked' => 1]);
});

it('says who locked each: the decider, else the person who counted', function () {
    $decided = $this->chain->approved($this->place, ($this->line)(), ($this->at)('1 10'));
    $straight = $this->chain->approved($this->bar, [], array_merge(($this->at)('1 11'), ['decision' => null, 'decided_at' => null, 'decided_by_user_id' => null]));

    app(Locks::class)->lockApproved();

    expect(StockCount::query()->findOrFail($decided->id)->locked_by_user_id)->toBe($this->f->admin->id)
        ->and(StockCount::query()->findOrFail($straight->id)->locked_by_user_id)->toBe($this->f->chef->id);
});

it('is idempotent: a second run finds nothing and changes nothing', function () {
    $this->chain->approved($this->place, ($this->line)(), ($this->at)('1 10'));
    $this->chain->approved($this->place, [], ($this->at)('2 10'));
    app(Locks::class)->lockApproved();
    $before = DB::table('stock_counts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    expect(app(Locks::class)->lockApproved())->toBe(0)
        ->and(DB::table('stock_counts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});

it('leaves every count that is not approved alone, and continues an existing chain', function () {
    $existing = $this->chain->link($this->place, ($this->line)());
    $draft = StockCount::factory()->forShift(App\Modules\Shifts\Models\ShiftInstance::factory()->locked()->create(['branch_id' => $this->branch->id, 'station_id' => $this->f->kitchen->id, 'created_by_user_id' => $this->f->chef->id, 'date' => '2026-12-01']), $this->place->id)->closing()->create(['created_by_user_id' => $this->f->chef->id]);
    $pending = $this->chain->approved($this->place, ($this->line)(), ['status' => CountStatus::PendingApproval, 'decision' => null, 'decided_at' => null, 'decided_by_user_id' => null]);
    $approved = $this->chain->approved($this->place, ($this->line)(), ($this->at)('5 10'));

    expect(app(Locks::class)->lockApproved())->toBe(1);

    expect(StockCount::query()->findOrFail($approved->id)->chain_seq)->toBe(2)
        ->and(StockCount::query()->findOrFail($approved->id)->previous_hash)->toBe(StockCount::query()->findOrFail($existing->id)->payload_hash)
        ->and(StockCount::query()->findOrFail($draft->id)->status)->toBe(CountStatus::Draft)
        ->and(StockCount::query()->findOrFail($pending->id)->status)->toBe(CountStatus::PendingApproval)
        ->and(StockCount::query()->findOrFail($pending->id)->chain_seq)->toBeNull();
});

it('works across branches and leaves each branch\'s chain on its own', function () {
    $mine = $this->chain->approved($this->place, ($this->line)(), ($this->at)('1 10'));
    $other = actInBranch(Branch::factory()->create());
    $f2 = ShiftsFixture::build($other, $this);
    $place2 = $f2->place($f2->kitchen);
    $theirs = (new ChainFixture($f2))->approved($place2, [], ($this->at)('1 10'));
    actInBranch($this->branch);

    expect(app(Locks::class)->lockApproved())->toBe(2);

    expect(StockCount::query()->forBranch($this->branch->id)->findOrFail($mine->id)->chain_seq)->toBe(1)
        ->and(StockCount::query()->forBranch($other->id)->findOrFail($theirs->id)->chain_seq)->toBe(1)
        ->and(app(CurrentBranch::class)->idOrNull())->toBe($this->branch->id, 'the branch the caller was in is put back');
});

it('is what the end of migration 12 does, and the migration can run again on a populated database', function () {
    $files = glob(database_path('migrations/*_add_stock_counts_locked_rows_triggers.php')) ?: [];
    expect($files)->toHaveCount(1);
    $migration = require $files[0];
    $a = $this->chain->approved($this->place, ($this->line)(), ($this->at)('1 10'));
    $b = $this->chain->approved($this->place, [], ($this->at)('2 10'));

    $migration->up();
    $migration->up();

    expect(StockCount::query()->findOrFail($a->id)->chain_seq)->toBe(1)
        ->and(StockCount::query()->findOrFail($b->id)->chain_seq)->toBe(2)
        ->and(app(HashChain::class)->verify($this->place->id)->toArray())->toMatchArray(['ok' => true, 'checked' => 2]);
    expect(fn () => DB::table('stock_counts')->where('id', $a->id)->update(['decision_note' => 'x']))->toThrow(Illuminate\Database\QueryException::class);
    expect(DB::table('information_schema.triggers')->where('trigger_schema', dbSchema())->where('trigger_name', 'stock_counts_locked_forbid_update')->count())->toBe(1);
});

it('has a command to run it again by hand', function () {
    $this->chain->approved($this->place, ($this->line)(), ($this->at)('1 10'));

    $this->artisan('shifts:lock-approved')->expectsOutputToContain('Locked 1')->assertExitCode(0);
    $this->artisan('shifts:lock-approved')->expectsOutputToContain('Locked 0')->assertExitCode(0);
});
