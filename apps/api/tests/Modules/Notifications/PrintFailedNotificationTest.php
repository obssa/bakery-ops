<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Notifications\Listeners\NotifyPrintFailed;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Enums\StationCode;
use App\Shared\Events\PrintJobFailed;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature');

/*
 * Step 5.5c (phase-5 plan section 5, NotifyPrintFailed): a receipt that will not be printed (PrintJobFailed, raised by Payments after the
 * commit, once per job) is told to the cashier who asked and to the managers on shift, in the inbox only, severity warning, one line per
 * job (print_failed:{job}). "On shift": the managers among the people on duty at any station of the branch (Recipients::onDuty: the
 * running shifts' rosters); when no manager is on duty, every active manager of the branch, so a failed receipt is never told to nobody
 * who can act. The line names the order and why (paper out, offline, cover open, unknown, not acknowledged) and opens the till.
 * Written from the payload alone: Notifications reads nothing of Payments. Queued after commit, run as the cashier, in the event's branch.
 */

beforeEach(function () {
    NotificationDrivers::allOff();
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->till = Station::factory()->create(['branch_id' => $this->branch->id, 'code' => StationCode::Till]);
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->otherCashier = makeUser($this->branch, 'cashier');
    $this->onShift = makeUser($this->branch, 'manager');
    $this->offShift = makeUser($this->branch, 'manager');
    $this->admin = makeUser($this->branch, 'admin');
    makeUser($this->branch, 'manager', ['is_active' => false]);
    withActor($this->cashier->id);
    $this->duty = [];
    app()->instance(StationPresenceResolver::class, new class($this) implements StationPresenceResolver
    {
        public function __construct(private readonly object $test) {}

        public function onDuty(Station $station): Collection
        {
            return new Collection($this->test->duty);
        }
    });
    $this->event = fn (array $over = []): PrintJobFailed => new PrintJobFailed(
        $over['branch'] ?? $this->branch->id,
        $over['job'] ?? 41,
        $over['order'] ?? 21,
        array_key_exists('number', $over) ? $over['number'] : 17,
        $over['key'] ?? 'paper_out',
        $over['by'] ?? $this->cashier->id,
    );
    $this->heard = fn () => Notification::query()->where('type', 'print_failed')->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (...$users) => collect($users)->map(fn ($u): int => $u->id)->sort()->values()->all();
});

it('is queued after commit on the default queue and registered for PrintJobFailed', function () {
    Queue::fake();

    event(($this->event)());

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyPrintFailed::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull()
        ->and(is_subclass_of(NotifyPrintFailed::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyPrintFailed::class, ShouldHandleEventsAfterCommit::class))->toBeTrue();
});

it('tells the cashier who asked and the managers on duty, not the managers off shift, the admin or another cashier', function () {
    $this->duty = [$this->onShift, $this->otherCashier];

    app(NotifyPrintFailed::class)->handle(($this->event)());

    expect(($this->heard)())->toBe(($this->ids)($this->cashier, $this->onShift));
});

it('tells every active manager when no manager is on duty', function () {
    $this->duty = [$this->otherCashier];

    app(NotifyPrintFailed::class)->handle(($this->event)());

    expect(($this->heard)())->toBe(($this->ids)($this->cashier, $this->onShift, $this->offShift));
});

it('writes one warning line per job in the inbox only, opening the till: a redelivery adds nothing', function () {
    NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->cashier->id]);
    NotificationDrivers::array();
    $this->duty = [$this->onShift];

    app(NotifyPrintFailed::class)->handle(($this->event)());
    app(NotifyPrintFailed::class)->handle(($this->event)());
    app(NotifyPrintFailed::class)->handle(($this->event)(['job' => 42]));

    $line = Notification::query()->where('user_id', $this->cashier->id)->orderBy('id')->first();
    expect(Notification::query()->count())->toBe(4)
        ->and($line->severity)->toBe('warning')
        ->and($line->dedupe_key)->toBe('print_failed:41')
        ->and($line->deep_link)->toBe('/till')
        ->and($line->payload['caused_by_user_id'] ?? null)->toBe($this->cashier->id)
        ->and(NotificationDelivery::query()->count())->toBe(0, 'the inbox only');
});

it('names the order and why it was not printed, each side in its own language', function () {
    $text = app(NotificationText::class);

    foreach (['paper_out', 'offline', 'cover_open', 'unknown', 'unacknowledged'] as $key) {
        $rendered = $text->render('print_failed', ($this->event)(['key' => $key])->payload());
        expect($rendered->title->en)->toContain('#17')
            ->and($rendered->title->am)->toContain('#17')
            ->and($rendered->body->en)->not->toContain($key)
            ->and($rendered->body->am)->not->toMatch('/[A-Za-z]/')
            ->and($rendered->body->am)->not->toBe($rendered->body->en);
    }
    $plain = $text->render('print_failed', ($this->event)(['number' => null])->payload());
    expect($plain->title->en)->not->toContain('#')
        ->and($plain->title->am)->not->toContain('#');
});

it('is in the registry: the inbox only, keyed per job, opening the till', function () {
    $payload = ($this->event)(['job' => 73])->payload();

    expect(NotificationTypes::has('print_failed'))->toBeTrue()
        ->and(NotificationTypes::channels('print_failed'))->toBe(['in_app'])
        ->and(NotificationTypes::severity('print_failed', $payload))->toBe('warning')
        ->and(NotificationTypes::inboxKey('print_failed', $payload))->toBe('print_failed:73')
        ->and(NotificationTypes::gateKey('print_failed', $payload))->toBeNull()
        ->and(NotificationTypes::deepLink('print_failed', $payload))->toBe('/till');
});
