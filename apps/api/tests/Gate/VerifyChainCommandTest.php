<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\ChainBroken;
use App\Shared\Models\Branch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\ChainFixture;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

uses(CommitsForConcurrency::class);

/*
 * `php artisan shifts:verify-chain` (step 3.4; phase-3 plan section 5, last paragraph): scheduled daily at 03:00 Africa/Addis_Ababa by
 * the existing schedule:run cron line. It verifies every chain of every branch, prints and logs the head hash of each place (the
 * figure the admin screen shows and the weekly summary will carry outside the database), exits 0 when every chain is whole and 1 when
 * any is not, and dispatches ChainBroken for every failure it finds. The tamper plays a person with write access to the database.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->chain = new ChainFixture($this->f);
    $this->place = $this->f->place($this->f->kitchen);
    $this->bar = $this->f->place($this->f->kitchen, App\Modules\Inventory\Enums\LocationType::Bar);
});

it('is scheduled daily at 03:00 Addis Ababa time without overlapping itself', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'shifts:verify-chain'));

    expect($event)->not->toBeNull('shifts:verify-chain is on the schedule')
        ->and($event->expression)->toBe('0 3 * * *')
        ->and((string) $event->timezone)->toBe('Africa/Addis_Ababa')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('prints and logs the head hash of every place and exits 0 when every chain is whole', function () {
    Event::fake([ChainBroken::class]);
    Log::spy();
    $kitchen = $this->chain->chain($this->place, 3);
    $bar = $this->chain->chain($this->bar, 1);
    $kitchenHead = StockCount::query()->findOrFail($kitchen[2]->id)->payload_hash;
    $barHead = StockCount::query()->findOrFail($bar[0]->id)->payload_hash;

    $this->artisan('shifts:verify-chain')
        ->expectsOutputToContain($kitchenHead)
        ->expectsOutputToContain($barHead)
        ->assertExitCode(0);

    Event::assertNotDispatched(ChainBroken::class);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => ($context['head_hash'] ?? null) === $kitchenHead && ($context['location_id'] ?? null) === $this->place->id && ($context['checked'] ?? null) === 3)->once();
});

it('checks the chains of every branch', function () {
    Event::fake([ChainBroken::class]);
    $this->chain->chain($this->place, 1);
    $other = actInBranch(Branch::factory()->create());
    $f2 = ShiftsFixture::build($other, $this);
    $place2 = $f2->place($f2->kitchen);
    $second = (new ChainFixture($f2))->chain($place2, 2);
    actInBranch($this->branch);
    $head = StockCount::query()->forBranch($other->id)->findOrFail($second[1]->id)->payload_hash;

    $this->artisan('shifts:verify-chain')->expectsOutputToContain($head)->assertExitCode(0);
});

it('exits 1 and announces ChainBroken for the first bad link of a tampered chain, naming the person who made that link', function () {
    Event::fake([ChainBroken::class]);
    $links = $this->chain->chain($this->place, 3);
    ChainFixture::withoutTriggers(['stock_counts_locked_forbid_update'], fn () => DB::table('stock_counts')->where('id', $links[1]->id)->update(['decision_note' => 'rewritten']));

    $this->artisan('shifts:verify-chain')->assertExitCode(1);

    Event::assertDispatchedTimes(ChainBroken::class, 1);
    Event::assertDispatched(ChainBroken::class, function (ChainBroken $e) use ($links): bool {
        $p = $e->payload();

        return $e->branchId === $this->branch->id && $p['location_id'] === $this->place->id && $p['location_name_en'] !== '' && $p['location_name_am'] !== ''
            && $p['first_bad_chain_seq'] === 2 && $p['kind'] === 'payload_mismatch' && $p['count_id'] === $links[1]->id
            && $p['caused_by_user_id'] === $this->f->admin->id;
    });
});

it('announces one ChainBroken per failure it finds and leaves the whole chains alone', function () {
    Event::fake([ChainBroken::class]);
    $links = $this->chain->chain($this->place, 4);
    $whole = $this->chain->chain($this->bar, 2);
    ChainFixture::withoutTriggers(['stock_counts_locked_forbid_update'], function () use ($links) {
        DB::table('stock_counts')->where('id', $links[1]->id)->update(['payload_hash' => str_repeat('b', 64)]);
    });

    $this->artisan('shifts:verify-chain')->assertExitCode(1);

    Event::assertDispatchedTimes(ChainBroken::class, 2);
    Event::assertDispatched(ChainBroken::class, fn (ChainBroken $e): bool => $e->payload()['first_bad_chain_seq'] === 2 && $e->payload()['kind'] === 'hash_mismatch');
    Event::assertDispatched(ChainBroken::class, fn (ChainBroken $e): bool => $e->payload()['first_bad_chain_seq'] === 3 && $e->payload()['kind'] === 'previous_mismatch');
    Event::assertNotDispatched(ChainBroken::class, fn (ChainBroken $e): bool => $e->payload()['location_id'] === $this->bar->id);
    expect($whole)->toHaveCount(2);
});

it('can check one branch alone', function () {
    Event::fake([ChainBroken::class]);
    $this->chain->chain($this->place, 1);

    $this->artisan('shifts:verify-chain', ['--branch' => $this->branch->id])->assertExitCode(0);
    $this->artisan('shifts:verify-chain', ['--branch' => 999999])->assertExitCode(2);
});
