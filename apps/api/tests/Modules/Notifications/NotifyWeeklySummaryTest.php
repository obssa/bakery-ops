<?php

declare(strict_types=1);

use App\Modules\Notifications\Listeners\NotifyWeeklySummary;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationDrivers;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 4.4d (phase-4 plan sections 5 and 10.2 R-L): WeeklySummaryReady tells the people who run the analytics (analytics.manage: the admin)
 * how the week went, in the inbox, and by Telegram and push where those are switched on (both are off on the live host until Ramin creates
 * the keys; CI's array driver stands in for them), never by email. The line says the week, the revenue and the orders against the week
 * before, the products with the best and the lowest margin, the channels, the refunds and voids, the count variances and the stock-outs,
 * each name in the reader's language, and carries a signed seven-day link to the PDF of the week in that language: the event carries no
 * URL, the link is made here (Shared\Reports\WeeklySummaryLinks, which Analytics binds). One line per person per week and run, however
 * often the event is delivered; the inbox line opens the dashboards on that week. Queued after commit on the default queue, run as the
 * person who asked (nobody, for the Monday job), in the event's branch.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 04:00:00', 'UTC'));
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin', ['locale' => 'en']);
    $this->amharicAdmin = makeUser($this->branch, 'admin', ['locale' => 'am']);
    $this->manager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->gone = makeUser($this->branch, 'admin', ['is_active' => false]);
    withActor($this->admin->id);
    $this->heard = fn () => Notification::query()->where('type', 'weekly_summary')->orderBy('user_id')->pluck('user_id')->all();
});

it('is queued after commit on the default queue and registered for its event', function () {
    Queue::fake();

    event(NotificationEvents::weeklySummaryReady($this->branch->id));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyWeeklySummary::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull()
        ->and(is_subclass_of(NotifyWeeklySummary::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyWeeklySummary::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the holders of analytics.manage, the person who asked included, and nobody else', function () {
    app(NotifyWeeklySummary::class)->handle(NotificationEvents::weeklySummaryReady($this->branch->id, ['byUserId' => $this->admin->id, 'run' => 'manual-1']));

    expect(($this->heard)())->toBe([$this->admin->id, $this->amharicAdmin->id]);
});

it('writes one line per person per week and run: a redelivery adds nothing, another run is a new line', function () {
    $event = NotificationEvents::weeklySummaryReady($this->branch->id);
    app(NotifyWeeklySummary::class)->handle($event);
    app(NotifyWeeklySummary::class)->handle($event);
    expect(Notification::query()->count())->toBe(2);

    app(NotifyWeeklySummary::class)->handle(NotificationEvents::weeklySummaryReady($this->branch->id, ['run' => 'manual-2', 'byUserId' => $this->admin->id]));

    $line = Notification::query()->where('user_id', $this->admin->id)->orderBy('id')->first();
    expect(Notification::query()->count())->toBe(4)
        ->and($line->severity)->toBe('info')
        ->and($line->dedupe_key)->toBe('weekly_summary:2026-11-16:scheduled')
        ->and($line->deep_link)->toBe('/admin/analytics?from=2026-11-16&to=2026-11-22')
        ->and(array_key_exists('caused_by_user_id', $line->payload))->toBeTrue()
        ->and($line->payload['caused_by_user_id'])->toBeNull('the Monday job is nobody');
});

it('says how the week went, each name in the reader\'s language, with a signed link to the PDF in that language', function () {
    app(NotifyWeeklySummary::class)->handle(NotificationEvents::weeklySummaryReady($this->branch->id));

    $line = Notification::query()->where('user_id', $this->admin->id)->sole();
    expect($line->title_en)->toContain('2026-11-16')->toContain('2026-11-22')
        ->and($line->body_en)->toContain('300.00')->toContain('+25.00%')->toContain('240.00')->toContain('4')
        ->toContain('Croissant')->toContain('Cake')->toContain('Cash 66.70%')->toContain('Telebirr 33.30%')
        ->toContain('20.00')->toContain('30.00')->toContain('5.50')->toContain('Flour')
        ->and($line->body_am)->toContain('300.00')->toContain('ክሮሳንት')->toContain('ጥሬ ገንዘብ 66.70%')->toContain('ዱቄት')
        ->and($line->body_am)->not->toContain('Croissant')
        ->and($line->title_am)->not->toBe($line->title_en);

    preg_match('~https?://\S+~', $line->body_en, $en);
    preg_match('~https?://\S+~', $line->body_am, $am);
    expect($en[0] ?? null)->toContain('/api/v1/weekly-summaries/2026-11-16')->toContain('locale=en')->toContain('signature=')
        ->and($am[0] ?? null)->toContain('locale=am')
        ->and($line->payload['pdf_url_en'] ?? null)->toBe($en[0])
        ->and($line->payload['pdf_url_am'] ?? null)->toBe($am[0])
        ->and($line->payload['pdf_expires_at'] ?? null)->toBe(CarbonImmutable::now()->addDays(7)->toIso8601String())
        ->and(mb_strlen($line->body_en))->toBeLessThanOrEqual(1000)
        ->and(mb_strlen($line->body_am))->toBeLessThanOrEqual(1000);

    $this->flushHeaders();
    $this->get($en[0])->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('says so when the week sold nothing and nothing ran out', function () {
    $quiet = NotificationEvents::weeklySummaryReady($this->branch->id, ['summary' => [
        'revenue' => '0.00', 'revenue_before' => '0.00', 'revenue_change_pct' => null, 'orders_count' => 0, 'orders_before' => 0, 'orders_change_pct' => null,
        'best_items' => [], 'worst_items' => [], 'channels' => [], 'stockout_count' => 0, 'stockout_items' => [], 'variance_line_count' => 0, 'cash_variance_total' => '0.00',
        'refund_count' => 0, 'refund_total' => '0.00', 'voided_count' => 0, 'voided_total' => '0.00',
    ]]);

    $rendered = app(NotificationText::class)->render('weekly_summary', $quiet->payload());

    expect($rendered->body->en)->toContain('0.00')->not->toMatch('/:[a-z_]+/')
        ->and($rendered->body->en)->toContain((string) trans('notifications.weekly.none', [], 'en'))
        ->and($rendered->body->en)->toContain((string) trans('notifications.weekly.no_change', [], 'en'))
        ->and($rendered->body->am)->toContain((string) trans('notifications.weekly.none', [], 'am'));
});

it('reaches Telegram through the array driver in CI, with the link in the message', function () {
    $sent = NotificationDrivers::array();
    NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);

    app(NotifyWeeklySummary::class)->handle(NotificationEvents::weeklySummaryReady($this->branch->id));

    $telegram = array_values(array_filter($sent->sent(), fn (array $entry): bool => $entry['channel'] === 'telegram'));
    expect(NotificationDelivery::query()->count())->toBe(1)
        ->and($telegram)->toHaveCount(1)
        ->and($telegram[0]['user_id'])->toBe($this->admin->id)
        ->and($telegram[0]['body'])->toContain('/api/v1/weekly-summaries/2026-11-16')->toContain('signature=');
});

it('reaches nobody outside the app while every external driver is off', function () {
    NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);

    app(NotifyWeeklySummary::class)->handle(NotificationEvents::weeklySummaryReady($this->branch->id));

    expect(Notification::query()->count())->toBe(2)->and(NotificationDelivery::query()->count())->toBe(0);
});

it('is in the registry with in-app, push and Telegram, keyed per week and run', function () {
    $payload = NotificationEvents::weeklySummaryReady(1, ['run' => 'manual-7'])->payload();

    expect(NotificationTypes::has('weekly_summary'))->toBeTrue()
        ->and(NotificationTypes::channels('weekly_summary'))->toBe(['in_app', 'push', 'telegram'])
        ->and(NotificationTypes::severity('weekly_summary', $payload))->toBe('info')
        ->and(NotificationTypes::inboxKey('weekly_summary', $payload))->toBe('weekly_summary:2026-11-16:manual-7')
        ->and(NotificationTypes::gateKey('weekly_summary', $payload))->toBeNull()
        ->and(NotificationTypes::deepLink('weekly_summary', $payload))->toBe('/admin/analytics?from=2026-11-16&to=2026-11-22');
});
