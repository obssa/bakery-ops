<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\RebuildDay;
use App\Modules\Analytics\Listeners\TouchToday;
use App\Shared\Events\OrderPaid;
use App\Shared\Events\OrderVoided;
use App\Shared\Events\PaymentDisputed;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Events\PaymentVerified;
use App\Shared\Events\PaymentVoided;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnalyticsDay;
use Tests\Support\OrderFloor;
use Tests\Support\Receipts;

pest()->group('module:analytics', 'feature');

/*
 * The debounced touch of today (phase-4 plan section 5, step 4.4b; ADR 0014): a payment taken, verified, disputed or voided, and an order paid
 * or voided, queue Analytics' TouchToday after the commit; it asks for RebuildDay(branch, the local day the event happened, trigger touch)
 * sixty seconds out. RebuildDay is unique per branch and day for 120 s (the cache's atomic lock; the live host's cache is the database's), and
 * the lock is let go when the job starts, so twenty payments in a minute give ONE rebuild that reads all twenty, and a payment that lands
 * while a rebuild runs asks for the next one. CI runs QUEUE_CONNECTION=sync, where every job runs at once: the debounce is asserted with
 * RebuildDay faked over the database cache store, and its sync twin asserts what the rebuilds wrote. The three shift rules are on: the
 * floor's till, kitchen and bar have running shifts. Friday 2026-11-20, 09:00 local (06:00Z).
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'));
    $this->f = OrderFloor::open($this);
    $this->bottles = function (string $qty): array {
        $f = $this->f;
        $order = $f->send([$f->menu->line($f->menu->water, $qty)]);
        $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();

        return $order;
    };
    $this->touches = fn (): array => Queue::pushed(RebuildDay::class)->map(fn (RebuildDay $job): array => [$job->branchId, $job->day, $job->trigger, $job->delay])->values()->all();
});

it('asks for one rebuild of today, sixty seconds out, for twenty payments in a minute (the database cache lock)', function () {
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    $order = ($this->bottles)('20');
    Queue::fake([RebuildDay::class]);

    foreach (range(1, 20) as $n) {
        Receipts::pay($this, $this->f, $order['id'], 'cash', '30.00', ['tendered_amount' => '30.00'])->assertCreated();
        $this->travel(2)->seconds();
    }

    $b = $this->f->branch->id;
    expect(($this->touches)())->toBe([[$b, '2026-11-20', 'touch', 60]], 'twenty payments and the order paid: one rebuild')
        ->and(DB::table('cache_locks')->where('key', 'like', '%RebuildDay:'.$b.':2026-11-20')->count())->toBe(1, 'held in the database cache')
        ->and(DB::table('analytics_days')->count())->toBe(0, 'nothing is built until a worker runs the job');

    // past the lock's 120 s the next payment asks again (a lost job never keeps the day from being touched)
    $this->travel(2)->minutes();
    $next = ($this->bottles)('1');
    Receipts::pay($this, $this->f, $next['id'], 'cash', '30.00', ['tendered_amount' => '30.00'])->assertCreated();

    expect(($this->touches)())->toBe([[$b, '2026-11-20', 'touch', 60], [$b, '2026-11-20', 'touch', 60]]);
});

it('rebuilds today from everything paid when the queue runs the jobs (the sync twin)', function () {
    $f = $this->f;
    $order = ($this->bottles)('3');
    Receipts::pay($this, $f, $order['id'], 'cash', '30.00', ['tendered_amount' => '50.00'])->assertCreated();
    $this->travel(5)->seconds();
    Receipts::pay($this, $f, $order['id'], 'telebirr', '60.00', ['reference_no' => 'FT2611200001'])->assertCreated();

    $day = DB::table('analytics_days')->where('branch_id', $f->branch->id)->where('day', '2026-11-20')->first();
    $rows = AnalyticsDay::rows($f->branch->id, '2026-11-20');
    $channels = collect($rows['daily_channel_totals'])->mapWithKeys(fn (array $r): array => [$r['channel_id'] => [$r['receipts_count'], $r['amount']]])->all();
    expect($day?->trigger)->toBe('touch')
        ->and($rows['daily_sales'][0]['orders_count'])->toBe(1)
        ->and($rows['daily_sales'][0]['revenue'])->toBe('90.00')
        ->and($channels)->toBe([
            (int) DB::table('payment_channels')->where('branch_id', $f->branch->id)->where('code', 'cash')->value('id') => [1, '30.00'],
            (int) DB::table('payment_channels')->where('branch_id', $f->branch->id)->where('code', 'telebirr')->value('id') => [1, '60.00'],
        ]);
});

it('listens to every event that changes the figures of today: a payment taken, verified, disputed or voided, an order paid or voided', function () {
    $raw = app('events')->getRawListeners();

    foreach ([PaymentRecorded::class, PaymentVerified::class, PaymentDisputed::class, PaymentVoided::class, OrderPaid::class, OrderVoided::class] as $event) {
        expect($raw[$event] ?? [])->toContain([TouchToday::class, 'handle']);
    }
});

it('is queued on the default queue and only after the commit', function () {
    Queue::fake();

    event(new OrderPaid($this->f->branch->id, 1, '30.00', CarbonImmutable::now()));

    $queued = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === TouchToday::class);
    expect($queued)->toHaveCount(1)
        ->and($queued->first()->queue)->toBeNull('the default queue: the cron worker drains only that one')
        ->and(is_subclass_of(TouchToday::class, ShouldQueueAfterCommit::class))->toBeTrue();
});

it('touches nothing when the transaction that took the payment rolls back', function () {
    $order = ($this->bottles)('2');
    Queue::fake([RebuildDay::class]);

    try {
        DB::transaction(function () use ($order): void {
            event(new PaymentRecorded($this->f->branch->id, 1, $order['id'], 'cash', '30.00', '30.00'));
            throw new RuntimeException('the payment failed after it was announced');
        });
    } catch (RuntimeException) {
        // rolled back
    }

    Queue::assertNothingPushed();
});

it('rebuilds the local day the event happened on, even when the worker runs it after midnight', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 20:59:30', 'UTC')); // 23:59:30 local
    $event = new PaymentRecorded($this->f->branch->id, 1, 1, 'cash', '30.00', '0.00');
    Queue::fake([RebuildDay::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 21:00:40', 'UTC')); // the cron worker, 00:00:40 local on the 21st

    app(TouchToday::class)->handle($event);

    expect(($this->touches)())->toBe([[$this->f->branch->id, '2026-11-20', 'touch', 60]]);
});

it('does nothing for a branch that no longer exists', function () {
    Queue::fake([RebuildDay::class]);

    app(TouchToday::class)->handle(new PaymentRecorded(999_999, 1, 1, 'cash', '30.00', '0.00'));

    Queue::assertNothingPushed();
});

it('lets the lock go when the job starts, so a payment taken while a rebuild runs asks for the next one', function () {
    expect(new RebuildDay($this->f->branch->id, '2026-11-20', 'touch'))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});
