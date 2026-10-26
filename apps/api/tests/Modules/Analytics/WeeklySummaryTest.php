<?php

declare(strict_types=1);

use App\Modules\Analytics\Weekly\WeeklySummary;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Events\WeeklySummaryReady;
use App\Shared\Reports\WeeklySummaryLinks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Dashboards;
use Tests\Support\Exports;

pest()->group('module:analytics', 'feature');

/*
 * The weekly summary (phase-4 plan sections 4.6, 5 and 10.2 R-L, step 4.4d). WeeklySummary covers a Monday to Sunday of local days (the
 * previous complete week by default, in the branch's zone) and reads the summary tables only: revenue and orders against the week before
 * (and the change in percent, null when the week before had none), net revenue and the average ticket, refunds, voids and cancellations,
 * the products with the best and the lowest margin (three each, products that sold, never the same one twice), the money per channel
 * with its share, the count variances of the shifts, the stock-outs and the items that ran out most, and its freshness (as_of, the days not
 * built). `analytics:weekly-summary` runs on Monday at 07:00 Africa/Addis_Ababa (withoutOverlapping) and announces WeeklySummaryReady once
 * per branch and week; POST /analytics/weekly-summary/send (analytics.manage, Idempotency-Key, {week_of?}) builds the same summary and
 * announces it again, each time (a way to try the Telegram path), and answers 202. The event carries the figures and never a URL: the
 * signed seven-day PDF link is made where the message is written (Shared\Reports\WeeklySummaryLinks), and opens without a bearer token.
 * Today is Monday 2026-11-23, 07:00 local (04:00Z).
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->travelTo(CarbonImmutable::parse('2026-11-23 04:00:00', 'UTC'));
    $this->croissant = $d->product('Croissant', 'ክሮሳንት');
    $this->bread = $d->product('Bread', 'ዳቦ');
    $this->tea = $d->product('Tea', 'ሻይ');
    $this->juice = $d->product('Juice', 'ጭማቂ');
    $this->cake = $d->product('Cake', 'ኬክ');
    $this->unsold = $d->product('Donut', 'ዶናት');
    $this->flour = $d->ingredient('Flour', 'ዱቄት');
    $this->milk = $d->ingredient('Milk', 'ወተት');
    $cash = $d->channel('cash');
    $telebirr = $d->channel('telebirr');
    $this->ids = ['cash' => $cash, 'telebirr' => $telebirr];
    // the week 2026-11-16 to 2026-11-22: three days built, four not
    $d->rows->built('2026-11-16')
        ->sales('2026-11-16', ['orders_count' => 2, 'revenue' => '180.00', 'net_revenue' => '160.00', 'refund_count' => 1, 'refund_total' => '20.00',
            'voided_count' => 1, 'voided_total' => '30.00'])
        ->channel('2026-11-16', $cash, ['receipts_count' => 1, 'amount' => '100.00', 'net_amount' => '100.00'])
        ->channel('2026-11-16', $telebirr, ['receipts_count' => 1, 'amount' => '80.00', 'net_amount' => '80.00'])
        ->item('2026-11-16', $this->croissant->id, ['units_sold_qty' => '3.0000', 'revenue' => '180.00', 'cogs_total' => '45.50', 'margin_total' => '134.50'])
        ->item('2026-11-16', $this->flour->id, ['kind' => 'ingredient', 'stockout_count' => 2])
        ->item('2026-11-16', $this->unsold->id, ['produced_qty' => '6.0000', 'waste_qty' => '6.0000'])
        ->station('2026-11-16', $d->kitchen->id, ['variance_line_count' => 2, 'cash_variance_total' => '-5.00']);
    $d->rows->built('2026-11-17')
        ->sales('2026-11-17', ['orders_count' => 1, 'revenue' => '60.25', 'net_revenue' => '60.25'])
        ->channel('2026-11-17', $cash, ['receipts_count' => 1, 'amount' => '60.25', 'net_amount' => '60.25'])
        ->item('2026-11-17', $this->bread->id, ['units_sold_qty' => '1.0000', 'revenue' => '30.25', 'cogs_total' => '20.00', 'margin_total' => '10.25'])
        ->item('2026-11-17', $this->tea->id, ['units_sold_qty' => '2.0000', 'revenue' => '20.00', 'cogs_total' => '16.00', 'margin_total' => '4.00', 'cogs_complete' => false])
        ->item('2026-11-17', $this->juice->id, ['units_sold_qty' => '1.0000', 'revenue' => '10.00', 'cogs_total' => '9.00', 'margin_total' => '1.00'])
        ->item('2026-11-17', $this->milk->id, ['kind' => 'ingredient', 'stockout_count' => 1]);
    $d->rows->built('2026-11-18')
        ->sales('2026-11-18', ['orders_count' => 1, 'revenue' => '59.75', 'net_revenue' => '59.75', 'cancelled_count' => 1])
        ->item('2026-11-18', $this->cake->id, ['units_sold_qty' => '1.0000', 'revenue' => '59.75', 'cogs_total' => '64.75', 'margin_total' => '-5.00'])
        ->item('2026-11-18', $this->croissant->id, ['stockout_count' => 1])
        ->station('2026-11-18', $d->till->id, ['variance_line_count' => 1, 'cash_variance_total' => '10.50']);
    // the week before
    $d->rows->built('2026-11-10')->sales('2026-11-10', ['orders_count' => 2, 'revenue' => '240.00', 'net_revenue' => '240.00']);
    // a day of the week after it, never read
    $d->rows->built('2026-11-23')->sales('2026-11-23', ['orders_count' => 50, 'revenue' => '5000.00']);
    $this->send = function (array $body = [], ?string $key = null, string $as = 'admin'): TestResponse {
        loginAs($this->d->users[$as]);
        $this->flushHeaders();

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson('/api/v1/analytics/weekly-summary/send', $body);
    };
    $this->expected = [
        'week_start' => '2026-11-16', 'week_end' => '2026-11-22', 'previous_start' => '2026-11-09', 'previous_end' => '2026-11-15', 'timezone' => 'Africa/Addis_Ababa',
        'as_of' => '2026-11-22T05:00:00Z', 'stale_days' => ['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'],
        'revenue' => '300.00', 'revenue_before' => '240.00', 'revenue_change_pct' => '25.00',
        'orders_count' => 4, 'orders_before' => 2, 'orders_change_pct' => '100.00',
        'net_revenue' => '280.00', 'avg_ticket' => '75.00',
        'refund_count' => 1, 'refund_total' => '20.00', 'voided_count' => 1, 'voided_total' => '30.00', 'cancelled_count' => 1,
        'best_items' => [
            ['item_id' => $this->croissant->id, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'units_sold_qty' => '3.0000', 'revenue' => '180.00', 'margin_total' => '134.50', 'margin_pct' => '74.72', 'cogs_complete' => true],
            ['item_id' => $this->bread->id, 'name_en' => 'Bread', 'name_am' => 'ዳቦ', 'units_sold_qty' => '1.0000', 'revenue' => '30.25', 'margin_total' => '10.25', 'margin_pct' => '33.88', 'cogs_complete' => true],
            ['item_id' => $this->tea->id, 'name_en' => 'Tea', 'name_am' => 'ሻይ', 'units_sold_qty' => '2.0000', 'revenue' => '20.00', 'margin_total' => '4.00', 'margin_pct' => '20.00', 'cogs_complete' => false],
        ],
        'worst_items' => [
            ['item_id' => $this->cake->id, 'name_en' => 'Cake', 'name_am' => 'ኬክ', 'units_sold_qty' => '1.0000', 'revenue' => '59.75', 'margin_total' => '-5.00', 'margin_pct' => '-8.37', 'cogs_complete' => true],
            ['item_id' => $this->juice->id, 'name_en' => 'Juice', 'name_am' => 'ጭማቂ', 'units_sold_qty' => '1.0000', 'revenue' => '10.00', 'margin_total' => '1.00', 'margin_pct' => '10.00', 'cogs_complete' => true],
        ],
        'channels' => [
            ['channel_id' => $cash, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'amount' => '160.25', 'share' => '66.70'],
            ['channel_id' => $telebirr, 'code' => 'telebirr', 'name_en' => 'Telebirr', 'name_am' => 'ቴሌብር', 'amount' => '80.00', 'share' => '33.30'],
        ],
        'variance_line_count' => 3, 'cash_variance_total' => '5.50',
        'stockout_count' => 4,
        'stockout_items' => [
            ['item_id' => $this->flour->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት', 'stockout_count' => 2],
            ['item_id' => $this->croissant->id, 'name_en' => 'Croissant', 'name_am' => 'ክሮሳንት', 'stockout_count' => 1],
            ['item_id' => $this->milk->id, 'name_en' => 'Milk', 'name_am' => 'ወተት', 'stockout_count' => 1],
        ],
    ];
});

it('covers the previous Monday to Sunday of local days', function (string $utc, string $monday) {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

    expect(WeeklySummary::lastWeek('Africa/Addis_Ababa'))->toBe($monday);
})->with([
    'Monday 07:00 local' => ['2026-11-23 04:00:00', '2026-11-16'],
    'Monday 00:00 local, still Sunday in UTC' => ['2026-11-22 21:00:00', '2026-11-16'],
    'Sunday 23:59:59 local: that week is not over' => ['2026-11-22 20:59:59', '2026-11-09'],
    'Wednesday' => ['2026-11-25 09:00:00', '2026-11-16'],
]);

it('gives the figures of the summaries for the week and the week before', function () {
    expect(app(WeeklySummary::class)->build('2026-11-16'))->toBe($this->expected);
});

it('reads the summaries and the names behind the contracts, nothing else', function () {
    $tables = [];
    DB::listen(function (QueryExecuted $query) use (&$tables): void {
        preg_match_all('/\b(?:from|join|into|update)\s+[`"]?([a-z_][a-z0-9_]*)[`"]?/i', $query->sql, $m);
        $tables = array_merge($tables, array_map('strtolower', $m[1]));
    });

    app(WeeklySummary::class)->build('2026-11-16');

    $allowed = ['analytics_days', 'daily_sales', 'daily_channel_totals', 'daily_item_metrics', 'daily_station_metrics', 'items', 'units', 'categories', 'payment_channels'];
    expect(array_values(array_diff(array_unique($tables), $allowed)))->toBe([])
        ->and($tables)->toContain('daily_sales', 'daily_item_metrics');
});

it('says nothing sold and no change when a week has no figures', function () {
    $summary = app(WeeklySummary::class)->build('2026-10-05');

    expect($summary['revenue'])->toBe('0.00')
        ->and($summary['revenue_change_pct'])->toBeNull()
        ->and($summary['orders_change_pct'])->toBeNull()
        ->and($summary['best_items'])->toBe([])
        ->and($summary['worst_items'])->toBe([])
        ->and($summary['channels'])->toBe([])
        ->and($summary['as_of'])->toBeNull()
        ->and($summary['stale_days'])->toHaveCount(7);
});

it('is scheduled on Monday at 07:00 Addis Ababa time, never twice at once', function () {
    $found = collect(app(Schedule::class)->events())->filter(fn (ScheduledEvent $e): bool => str_ends_with(rtrim((string) $e->command), 'analytics:weekly-summary'))->values();
    expect($found)->toHaveCount(1);
    $entry = $found->first();
    $zone = $entry->timezone instanceof DateTimeZone ? $entry->timezone->getName() : (string) $entry->timezone;

    expect($entry->expression)->toBe('0 7 * * 1')
        ->and($zone)->toBe('Africa/Addis_Ababa')
        ->and($entry->withoutOverlapping)->toBeTrue()
        ->and($entry->isDue(app()))->toBeTrue('Monday 07:00 local');
    $this->travelTo(CarbonImmutable::parse('2026-11-23 07:00:00', 'UTC'));
    expect($entry->isDue(app()))->toBeFalse('07:00 UTC is 10:00 local');
    $this->travelTo(CarbonImmutable::parse('2026-11-22 04:00:00', 'UTC'));
    expect($entry->isDue(app()))->toBeFalse('a Sunday');
});

it('announces the summary of the week just ended once for a Monday, with the figures and no link', function () {
    Event::fake([WeeklySummaryReady::class]);

    $this->artisan('analytics:weekly-summary')->assertExitCode(0);
    $this->artisan('analytics:weekly-summary')->assertExitCode(0);

    Event::assertDispatchedTimes(WeeklySummaryReady::class, 1);
    Event::assertDispatched(WeeklySummaryReady::class, function (WeeklySummaryReady $event): bool {
        $payload = $event->payload();
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $event->branchId === $this->d->branch->id
            && $event->weekStart === '2026-11-16'
            && $payload['run'] === 'scheduled'
            && $payload['by_user_id'] === null
            && array_intersect_key($payload, $this->expected) === $this->expected
            && ! str_contains($json, 'http') && ! str_contains($json, 'signature');
    });
});

it('announces again for another week, and a branch of its own when asked', function () {
    Event::fake([WeeklySummaryReady::class]);

    $this->artisan('analytics:weekly-summary')->assertExitCode(0);
    $this->artisan('analytics:weekly-summary', ['--week' => '2026-11-11'])->assertExitCode(0);
    $this->artisan('analytics:weekly-summary', ['--branch' => (string) $this->d->branch->id, '--week' => '2026-11-11'])->assertExitCode(0);

    expect(Event::dispatched(WeeklySummaryReady::class)->map(fn (array $args): string => $args[0]->weekStart)->all())->toBe(['2026-11-16', '2026-11-09']);
    $this->artisan('analytics:weekly-summary', ['--week' => '2026-11-23'])->assertExitCode(2);
});

it('puts the summary in the admin\'s inbox when the queue runs it (the sync twin)', function () {
    $this->artisan('analytics:weekly-summary')->assertExitCode(0);

    $lines = Notification::query()->where('type', 'weekly_summary')->get();
    expect($lines)->toHaveCount(1)
        ->and($lines->first()->user_id)->toBe($this->d->users['admin']->id)
        ->and($lines->first()->title_en)->toContain('2026-11-16')->toContain('2026-11-22')
        ->and($lines->first()->body_en)->toContain('300.00')->toContain('/api/v1/weekly-summaries/2026-11-16')
        ->and($lines->first()->dedupe_key)->toBe('weekly_summary:2026-11-16:scheduled');
});

it('sends the summary when the admin asks, every time, and answers 202 with what it announced', function () {
    Event::fake([WeeklySummaryReady::class]);

    $first = ($this->send)()->assertStatus(202);
    $first->assertJsonPath('week_start', '2026-11-16')
        ->assertJsonPath('week_end', '2026-11-22')
        ->assertJsonPath('summary.revenue', '300.00')
        ->assertJsonPath('summary.best_items.0.name_en', 'Croissant');
    expect($first->json('run'))->toStartWith('manual-');
    $second = ($this->send)(['week_of' => '2026-11-12'])->assertStatus(202)->assertJsonPath('week_start', '2026-11-09')->assertJsonPath('summary.revenue', '240.00');

    Event::assertDispatchedTimes(WeeklySummaryReady::class, 2);
    expect(Event::dispatched(WeeklySummaryReady::class)->map(fn (array $args): ?int => $args[0]->payload()['by_user_id'])->unique()->all())->toBe([$this->d->users['admin']->id])
        ->and($second->json('run'))->not->toBe($first->json('run'))
        ->and(DB::table('audit_log')->where('action', 'analytics.weekly_summary.send')->pluck('after')->map(fn ($a) => json_decode((string) $a, true)['week_start'])->all())
        ->toBe(['2026-11-16', '2026-11-09']);
});

it('replays a send under the same key without announcing it again', function () {
    Event::fake([WeeklySummaryReady::class]);
    $key = (string) Str::uuid();

    $first = ($this->send)([], $key)->assertStatus(202);
    $again = ($this->send)([], $key)->assertStatus(202);

    expect($again->json('run'))->toBe($first->json('run'));
    Event::assertDispatchedTimes(WeeklySummaryReady::class, 1);
});

it('writes one more inbox line per send, so the Telegram path can be tried again', function () {
    ($this->send)()->assertStatus(202);
    ($this->send)()->assertStatus(202);

    expect(Notification::query()->where('type', 'weekly_summary')->where('user_id', $this->d->users['admin']->id)->count())->toBe(2);
});

it('refuses a week that is not over and a week_of that is not a date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00:00', 'UTC')); // Sunday 12:00 local

    ($this->send)(['week_of' => '2026-11-20'])->assertStatus(422)
        ->assertJsonPath('message_key', 'analytics.weekly.week_open')
        ->assertJsonPath('week_start', '2026-11-16')
        ->assertJsonPath('week_end', '2026-11-22');
    ($this->send)(['week_of' => '2026-12-01'])->assertStatus(422)->assertJsonPath('message_key', 'analytics.weekly.week_open');
    ($this->send)(['week_of' => '2026-02-30'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->send)()->assertStatus(202)->assertJsonPath('week_start', '2026-11-09', 'on a Sunday the last complete week is the one before');
});

it('is for analytics.manage: the admin', function (string $role, bool $allowed) {
    Event::fake([WeeklySummaryReady::class]);

    $response = ($this->send)([], null, $role);

    $allowed ? $response->assertStatus(202) : $response->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin']));

it('opens the signed seven-day PDF link without a bearer token, in the language it was made for', function () {
    $link = app(WeeklySummaryLinks::class)->link($this->d->branch->id, '2026-11-16', 'en');

    expect($link)->not->toBeNull()
        ->and($link->url)->toContain('/api/v1/weekly-summaries/2026-11-16')->toContain('signature=')
        ->and($link->expiresAt->toIso8601String())->toBe(CarbonImmutable::now()->addDays(7)->toIso8601String());

    $this->flushHeaders();
    $response = $this->get($link->url)->assertOk();
    $pdf = Exports::body($response);
    $text = Exports::pdfText($pdf);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and(Exports::pdfPages($pdf))->toBeLessThanOrEqual(3)
        ->and($text)->toContain('2026-11-16')->toContain('300.00')->toContain('25.00')->toContain('Croissant')->toContain('Cake')->toContain('Flour');

    $am = Exports::pdfText(Exports::body($this->get(app(WeeklySummaryLinks::class)->link($this->d->branch->id, '2026-11-16', 'am')->url)->assertOk()));
    expect($am)->toContain('ክሮሳንት')->not->toContain('Croissant');
});

it('refuses a changed or an expired link', function () {
    $url = app(WeeklySummaryLinks::class)->link($this->d->branch->id, '2026-11-16', 'en')->url;
    $this->flushHeaders();

    $this->getJson(str_replace('2026-11-16', '2026-11-09', $url))->assertForbidden()->assertJsonPath('message_key', 'analytics.weekly.link_invalid');
    $this->getJson(str_replace('locale=en', 'locale=am', $url))->assertForbidden()->assertJsonPath('message_key', 'analytics.weekly.link_invalid');
    $this->travel(7)->days();
    $this->travel(1)->seconds();
    $this->getJson($url)->assertForbidden()->assertJsonPath('message_key', 'analytics.weekly.link_invalid');
});
