<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Analytics\Jobs\RebuildDay;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Live\OverlapCursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AnalyticsDay;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/freshness (phase-4 plan section 4.6, step 4.4b): what the dashboards poll every 30 s to say "updated 2 min ago" and
 * to know when to fetch again. analytics.view_own, so every role may ask. ?from=&to= local dates (default the last 30 days, today included;
 * at most 400 days, 422 analytics.range.too_large; never after today, 422 analytics.range.future). The answer: {from, to, timezone, as_of
 * (the oldest rebuilt_at of the built days of the range), days: [{day, version, rebuilt_at, trigger, changed}] (built days only),
 * stale_days (the days of the range with no analytics_days row, "not built", never zero), running (a rebuild of a day of the range was
 * asked for and has not run yet)}. Conditional (ConditionalPoll): the ETag is made of the days' versions and `running`, so a poll gets 304
 * until a rebuild moves a version. Today is 2026-11-22 in Africa/Addis_Ababa (09:00 local).
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $croissant = $this->d->product();
    foreach (['2026-11-20', '2026-11-21'] as $day) {
        $sale = $this->d->sale("{$day} 07:00:00", [[$croissant, '1', '60.00']]);
        $this->d->pay($sale, 'cash', '60.00', "{$day} 07:00:00");
    }
    $this->rebuild = fn (string $day, string $trigger) => app(Rebuild::class)->day($this->d->branch->id, $day, $trigger);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:05:00', 'UTC')); // 00:05 local on the 22nd: the nightly run
    ($this->rebuild)('2026-11-20', 'nightly');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 05:00:00', 'UTC')); // 08:00 local
    ($this->rebuild)('2026-11-21', 'nightly');
    ($this->rebuild)('2026-11-21', 'touch');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));
    $this->poll = function (array $query = [], array $headers = [], string $as = 'waiter'): TestResponse {
        loginAs($this->d->{$as});

        return $this->withHeaders($headers)->getJson('/api/v1/analytics/freshness'.($query === [] ? '' : '?'.http_build_query($query)));
    };
});

it('tells any role the version, time and trigger of each built day of the range, the days not built, and as_of the oldest of them', function () {
    $response = ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22']);

    $response->assertOk()
        ->assertHeader('Cache-Control', ConditionalPoll::CACHE_CONTROL)
        ->assertHeader('ETag');
    expect($response->json())->toBe([
        'from' => '2026-11-19',
        'to' => '2026-11-22',
        'timezone' => 'Africa/Addis_Ababa',
        'as_of' => '2026-11-21T21:05:00Z',
        'days' => [
            ['day' => '2026-11-20', 'version' => 1, 'rebuilt_at' => '2026-11-21T21:05:00Z', 'trigger' => 'nightly', 'changed' => true],
            ['day' => '2026-11-21', 'version' => 2, 'rebuilt_at' => '2026-11-22T05:00:00Z', 'trigger' => 'touch', 'changed' => false],
        ],
        'stale_days' => ['2026-11-19', '2026-11-22'],
        'running' => false,
    ]);
});

it('reads the last thirty days, today included, when no range is given, and as_of is null when no day of it is built', function () {
    ($this->poll)()->assertOk()
        ->assertJsonPath('from', '2026-10-24')
        ->assertJsonPath('to', '2026-11-22')
        ->assertJsonCount(2, 'days')
        ->assertJsonCount(28, 'stale_days');

    ($this->poll)(['from' => '2026-11-01', 'to' => '2026-11-10'])->assertOk()
        ->assertJsonPath('as_of', null)
        ->assertJsonPath('days', [])
        ->assertJsonCount(10, 'stale_days');
});

it('answers 304 for an unchanged range after the settling window, and a new tag once a rebuild moves a version', function () {
    $first = ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'])->assertOk();
    $tag = (string) $first->headers->get('ETag');
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'], ['If-None-Match' => $tag])->assertStatus(304)->assertHeader('ETag', $tag)->assertContent('');
    ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'], ['If-None-Match' => 'W/'.$tag])->assertStatus(304);
    ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'], ['If-None-Match' => $tag], 'admin')->assertStatus(304);
    $other = ($this->poll)(['from' => '2026-11-20', 'to' => '2026-11-22'], ['If-None-Match' => $tag])->assertOk();
    expect($other->headers->get('ETag'))->not->toBe($tag, 'another range is another tag');

    ($this->rebuild)('2026-11-21', 'manual');

    $moved = ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'], ['If-None-Match' => $tag])->assertOk()->assertJsonPath('days.1.version', 3);
    expect($moved->headers->get('ETag'))->not->toBe($tag);
});

it('says running while a rebuild of a day of the range is asked for and not built yet, with its own tag, and not once a worker built it', function () {
    $quiet = ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'])->assertOk()->assertJsonPath('running', false);
    Queue::fake([RebuildDay::class]);
    $this->travel(10)->seconds();
    loginAs($this->d->admin);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/analytics/rebuild', ['from' => '2026-11-21'])->assertStatus(202);

    $busy = ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'], ['If-None-Match' => (string) $quiet->headers->get('ETag')])->assertOk()->assertJsonPath('running', true);
    expect($busy->headers->get('ETag'))->not->toBe($quiet->headers->get('ETag'));
    ($this->poll)(['from' => '2026-11-22', 'to' => '2026-11-22'])->assertOk()->assertJsonPath('running', false); // the day asked for is outside this range

    $this->travel(40)->seconds(); // the cron worker runs the job
    ($this->rebuild)('2026-11-21', 'manual');

    ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'])->assertOk()->assertJsonPath('running', false)->assertJsonPath('days.1.version', 3)->assertJsonPath('days.1.trigger', 'manual');
});

it('refuses a range over 400 days, one that ends after today, and a range the wrong way round', function (array $query, string $key, array $extra) {
    $response = ($this->poll)($query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with([
    'over 400 days' => [['from' => '2025-10-17', 'to' => '2026-11-21'], 'analytics.range.too_large', ['max_days' => 400]],
    'tomorrow' => [['from' => '2026-11-20', 'to' => '2026-11-23'], 'analytics.range.future', ['today' => '2026-11-22']],
    'from tomorrow' => [['from' => '2026-11-23'], 'analytics.range.future', ['today' => '2026-11-22']],
    'backwards' => [['from' => '2026-11-21', 'to' => '2026-11-20'], 'validation.failed', []],
    'not a date' => [['from' => '2026-02-30'], 'validation.failed', []],
]);

it('allows 400 days exactly', function () {
    ($this->poll)(['from' => '2025-10-18', 'to' => '2026-11-21'])->assertOk()->assertJsonCount(2, 'days')->assertJsonCount(398, 'stale_days');
});

it('lists only the caller\'s branch', function () {
    $other = AnalyticsDay::open();
    app(Rebuild::class)->day($other->branch->id, '2026-11-19', 'manual');
    actInBranch($this->d->branch);

    ($this->poll)(['from' => '2026-11-19', 'to' => '2026-11-22'])->assertOk()
        ->assertJsonPath('days.0.day', '2026-11-20')
        ->assertJsonPath('stale_days', ['2026-11-19', '2026-11-22']);
});
