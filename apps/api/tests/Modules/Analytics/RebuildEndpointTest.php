<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Analytics\Jobs\RebuildDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AnalyticsDay;

pest()->group('module:analytics', 'feature');

/*
 * POST /api/v1/analytics/rebuild (phase-4 plan sections 4.6 and 10.1, step 4.4b; ADR 0014): the admin (analytics.manage) asks for the summary
 * tables of a range of local days to be rebuilt. With sync it runs here and now, one Rebuild::day transaction a day, for at most 7 days (what
 * the live E2E uses: no cron wait) and answers 200 with what each day became; otherwise it queues one RebuildDay job a day for at most 31
 * days and answers 202. More is 422 analytics.range.too_large {max_days}, a day after today 422 analytics.range.future {today}, both
 * bilingual. A queued rebuild that still waits for overlapping days is 409 analytics.rebuild.running. One audit row (analytics.rebuild) records
 * who asked for which days; the rebuilds themselves are derived data and are not audited. Idempotent by key: a replay answers the same and
 * rebuilds nothing. Today is 2026-11-22 in Africa/Addis_Ababa (09:00 local).
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $croissant = $this->d->product();
    foreach (['2026-11-20' => '2', '2026-11-21' => '3'] as $day => $qty) {
        $sale = $this->d->sale("{$day} 07:00:00", [[$croissant, $qty, '60.00']]);
        $this->d->pay($sale, 'cash', (string) (60 * (int) $qty).'.00', "{$day} 07:00:00");
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));
    $this->ask = function (array $body, ?string $key = null, string $as = 'admin'): TestResponse {
        loginAs($this->d->{$as});

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson('/api/v1/analytics/rebuild', $body);
    };
    $this->days = fn (?int $branchId = null): array => DB::table('analytics_days')->where('branch_id', $branchId ?? $this->d->branch->id)->orderBy('day')
        ->get()->map(fn (object $r): array => [substr((string) $r->day, 0, 10), $r->trigger, (int) $r->version])->all();
    $this->audits = fn (): array => DB::table('audit_log')->where('action', 'analytics.rebuild')->orderBy('id')->get()->all();
});

it('rebuilds up to seven local days here and now for the admin and answers what each day became', function () {
    $response = ($this->ask)(['from' => '2026-11-16', 'to' => '2026-11-22', 'sync' => true]);

    $response->assertOk()
        ->assertJsonPath('from', '2026-11-16')
        ->assertJsonPath('to', '2026-11-22')
        ->assertJsonPath('sync', true)
        ->assertJsonCount(7, 'days')
        ->assertJsonPath('days.0.day', '2026-11-16')
        ->assertJsonPath('days.4.day', '2026-11-20')
        ->assertJsonPath('days.4.trigger', 'manual')
        ->assertJsonPath('days.4.version', 1)
        ->assertJsonPath('days.4.changed', true)
        ->assertJsonPath('days.4.rows.daily_sales', 1)
        ->assertJsonPath('days.6.day', '2026-11-22');
    expect(($this->days)())->toBe(array_map(fn (string $day): array => [$day, 'manual', 1], ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22']))
        ->and(AnalyticsDay::rows($this->d->branch->id, '2026-11-21')['daily_sales'][0]['revenue'])->toBe('180.00');

    $audits = ($this->audits)();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]->actor_id)->toBe($this->d->admin->id)
        ->and($audits[0]->branch_id)->toBe($this->d->branch->id)
        ->and(json_decode((string) $audits[0]->after, true))->toBe(['from' => '2026-11-16', 'to' => '2026-11-22', 'sync' => true, 'days' => 7]);
});

it('queues one rebuild a day for up to thirty-one days and answers 202 before any of them ran', function () {
    Queue::fake([RebuildDay::class]);

    $response = ($this->ask)(['from' => '2026-10-23', 'to' => '2026-11-22']);

    $response->assertStatus(202)
        ->assertJsonPath('from', '2026-10-23')
        ->assertJsonPath('to', '2026-11-22')
        ->assertJsonPath('sync', false)
        ->assertJsonPath('queued', 31)
        ->assertJsonCount(31, 'days')
        ->assertJsonPath('days.0', '2026-10-23')
        ->assertJsonPath('days.30', '2026-11-22');
    $pushed = Queue::pushed(RebuildDay::class)->map(fn (RebuildDay $job): array => [$job->branchId, $job->day, $job->trigger, $job->delay])->values()->all();
    expect($pushed)->toHaveCount(31)
        ->and($pushed[0])->toBe([$this->d->branch->id, '2026-10-23', 'manual', null])
        ->and($pushed[30])->toBe([$this->d->branch->id, '2026-11-22', 'manual', null])
        ->and(($this->days)())->toBe([], 'nothing is built until a worker runs the jobs');
    expect(json_decode((string) ($this->audits)()[0]->after, true))->toBe(['from' => '2026-10-23', 'to' => '2026-11-22', 'sync' => false, 'days' => 31]);
});

it('writes the queued days when the queue runs the jobs (the sync twin)', function () {
    ($this->ask)(['from' => '2026-11-20', 'to' => '2026-11-21'])->assertStatus(202)->assertJsonPath('queued', 2);

    expect(($this->days)())->toBe([['2026-11-20', 'manual', 1], ['2026-11-21', 'manual', 1]])
        ->and(AnalyticsDay::rows($this->d->branch->id, '2026-11-20')['daily_sales'][0]['revenue'])->toBe('120.00');
});

it('defaults to one day when only from is given', function () {
    ($this->ask)(['from' => '2026-11-21', 'sync' => true])->assertOk()->assertJsonPath('to', '2026-11-21')->assertJsonCount(1, 'days');

    expect(($this->days)())->toBe([['2026-11-21', 'manual', 1]]);
});

it('refuses an eighth day here and now and a thirty-second day on the queue with analytics.range.too_large, in both languages, writing nothing', function (array $body, int $max) {
    Queue::fake([RebuildDay::class]);

    ($this->ask)($body)->assertStatus(422)
        ->assertJsonPath('message_key', 'analytics.range.too_large')
        ->assertJsonPath('max_days', $max)
        ->assertJsonPath('message_en', fn (string $text): bool => $text !== '' && ! str_contains($text, 'analytics.'))
        ->assertJsonPath('message_am', fn (string $text): bool => preg_match('/\p{Ethiopic}/u', $text) === 1);

    Queue::assertNothingPushed();
    expect(($this->days)())->toBe([])->and(($this->audits)())->toBe([]);
})->with([
    'eight days inline' => [['from' => '2026-11-15', 'to' => '2026-11-22', 'sync' => true], 7],
    'thirty-two days queued' => [['from' => '2026-10-22', 'to' => '2026-11-22'], 31],
    'thirty-two days queued, sync false' => [['from' => '2026-10-22', 'to' => '2026-11-22', 'sync' => false], 31],
]);

it('refuses a day after today with analytics.range.future, and a range the wrong way round, a malformed date or no from with validation.failed', function (array $body, string $key) {
    $response = ($this->ask)($body)->assertStatus(422)->assertJsonPath('message_key', $key);
    if ($key === 'analytics.range.future') {
        $response->assertJsonPath('today', '2026-11-22')->assertJsonPath('message_am', fn (string $text): bool => preg_match('/\p{Ethiopic}/u', $text) === 1);
    }

    expect(($this->days)())->toBe([])->and(($this->audits)())->toBe([]);
})->with([
    'tomorrow' => [['from' => '2026-11-23', 'sync' => true], 'analytics.range.future'],
    'until tomorrow' => [['from' => '2026-11-21', 'to' => '2026-11-23', 'sync' => true], 'analytics.range.future'],
    'backwards' => [['from' => '2026-11-21', 'to' => '2026-11-20', 'sync' => true], 'validation.failed'],
    'not a date' => [['from' => '2026-11-31', 'sync' => true], 'validation.failed'],
    'no from' => [['to' => '2026-11-21', 'sync' => true], 'validation.failed'],
    'sync not a bool' => [['from' => '2026-11-21', 'sync' => 'soon'], 'validation.failed'],
]);

it('replays the same Idempotency-Key with the same answer, one audit row, and rebuilds nothing the second time', function () {
    $key = (string) Str::uuid();

    $first = ($this->ask)(['from' => '2026-11-20', 'to' => '2026-11-21', 'sync' => true], $key)->assertOk();
    $second = ($this->ask)(['from' => '2026-11-20', 'to' => '2026-11-21', 'sync' => true], $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and(($this->audits)())->toHaveCount(1)
        ->and(($this->days)())->toBe([['2026-11-20', 'manual', 1], ['2026-11-21', 'manual', 1]]);
});

it('answers 409 analytics.rebuild.running while a queued rebuild of overlapping days waits, and lets it through once those days are built', function () {
    Queue::fake([RebuildDay::class]);
    ($this->ask)(['from' => '2026-11-20', 'to' => '2026-11-21'])->assertStatus(202);

    ($this->ask)(['from' => '2026-11-21', 'to' => '2026-11-22'])->assertStatus(409)
        ->assertJsonPath('message_key', 'analytics.rebuild.running')
        ->assertJsonPath('from', '2026-11-20')
        ->assertJsonPath('to', '2026-11-21')
        ->assertJsonPath('asked_at', '2026-11-22T06:00:00Z')
        ->assertJsonPath('message_am', fn (string $text): bool => preg_match('/\p{Ethiopic}/u', $text) === 1);
    ($this->ask)(['from' => '2026-11-21', 'sync' => true])->assertStatus(409);
    // a day the waiting rebuild does not hold may be rebuilt
    ($this->ask)(['from' => '2026-11-22', 'sync' => true])->assertOk();
    expect(($this->audits)())->toHaveCount(2);

    // a worker runs the two waiting jobs
    $this->travel(30)->seconds();
    foreach (['2026-11-20', '2026-11-21'] as $day) {
        app(Rebuild::class)->day($this->d->branch->id, $day, 'manual');
    }

    ($this->ask)(['from' => '2026-11-21', 'sync' => true])->assertOk();
});

it('rebuilds only the caller\'s branch', function () {
    $other = AnalyticsDay::open();
    $sale = $other->sale('2026-11-21 07:00:00', [[$other->product(), '1', '999.00']]);
    $other->pay($sale, 'cash', '999.00', '2026-11-21 07:00:00');
    actInBranch($this->d->branch);

    ($this->ask)(['from' => '2026-11-21', 'sync' => true])->assertOk();

    expect(($this->days)())->toBe([['2026-11-21', 'manual', 1]])
        ->and(($this->days)($other->branch->id))->toBe([]);
});

it('is the admin\'s alone (analytics.manage): every other role is 403 auth.forbidden and nothing is written', function (string $role) {
    Queue::fake([RebuildDay::class]);
    $person = makeUser($this->d->branch, $role);
    loginAs($person);

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/analytics/rebuild', ['from' => '2026-11-21', 'sync' => true])
        ->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');

    Queue::assertNothingPushed();
    expect(($this->days)())->toBe([])->and(($this->audits)())->toBe([]);
})->with(['manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']);
