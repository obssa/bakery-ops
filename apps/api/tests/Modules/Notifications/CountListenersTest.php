<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Notifications\Listeners\NotifyCountSubmitted;
use App\Modules\Notifications\Listeners\NotifyVarianceDecided;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationEvents;

/*
 * Step 3.3c (phase-3 plan section 5): NotifyCountSubmitted tells the right people for the state the count is in (overseers and
 * shifts.cosign holders while it waits for a co-signature, the shifts.approve holders while it waits for a decision, nobody for a
 * count that needs neither; never the person who counted), NotifyVarianceDecided tells the person who counted. Both are queued after
 * commit on the default queue, run as the person the event names, and are idempotent through the inbox key.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->chef = makeUser($this->branch, 'chef');
    $this->sous = makeUser($this->branch, 'chef');
    $this->overseer = makeUser($this->branch, 'cashier');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->baker = makeUser($this->branch, 'cake_baker');
    withActor($this->admin->id);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $assign = fn (User $user, Station $station, bool $overseer = false) => UserStationAssignment::factory()->create([
        'branch_id' => $b, 'user_id' => $user->id, 'station_id' => $station->id, 'can_count' => true, 'is_overseer' => $overseer,
    ]);
    $assign($this->chef, $this->kitchen);
    $assign($this->sous, $this->kitchen);
    $assign($this->overseer, $this->kitchen, true);
    $assign($this->cashier, $this->till);
    $assign($this->baker, $this->cakeRoom);
    $this->kitchenId = $this->kitchen->id;
    $this->heard = fn (string $type = 'count_submitted') => Notification::query()->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (User ...$users) => collect($users)->map(fn (User $u): int => $u->id)->sort()->values()->all();
    $this->submitted = fn (string $status, int $countedBy, array $over = [], int $count = 61) => NotificationEvents::countSubmitted($b, $countedBy, $status, $count, $over + ['stationId' => $this->kitchenId]);
});

it('queues both listeners after commit on the default queue, each registered for its event', function () {
    Queue::fake();
    $events = [
        NotifyCountSubmitted::class => NotificationEvents::countSubmitted($this->branch->id, $this->chef->id),
        NotifyVarianceDecided::class => NotificationEvents::varianceDecided($this->branch->id, $this->chef->id, $this->admin->id),
    ];

    foreach ($events as $listener => $event) {
        event($event);
        $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === $listener);
        expect($pushed)->toHaveCount(1, "{$listener} is queued once for its event")
            ->and($pushed->first()->queue)->toBeNull('the default queue')
            ->and(is_subclass_of($listener, ShouldQueue::class))->toBeTrue()
            ->and(is_subclass_of($listener, ShouldHandleEventsAfterCommit::class))->toBeTrue();
    }
    expect(Notification::query()->count())->toBe(0, 'nothing is written in the request that dispatched the event');
})->group('module:notifications', 'feature');

it('tells the overseers and the holders of shifts.cosign when a count waits for a co-signature, but not the person who counted', function () {
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('awaiting_cosign', $this->chef->id));

    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->manager, $this->overseer), 'the sous chef is not an overseer, the cashier works at the till')
        ->and(Notification::query()->first()->deep_link)->toBe('/admin/shifts?count=61');
})->group('module:notifications', 'feature');

it('leaves the counter out even when they are the station overseer or the manager', function () {
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('awaiting_cosign', $this->overseer->id));
    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->manager));

    Notification::query()->delete();
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('awaiting_cosign', $this->manager->id, [], 62));
    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->overseer));
})->group('module:notifications', 'feature');

it('tells the holders of shifts.approve when a count waits for a decision, and nobody else', function () {
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('pending_approval', $this->chef->id));

    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->manager), 'the overseer holds no shifts.approve');

    Notification::query()->delete();
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('pending_approval', $this->manager->id, [], 62));
    expect(($this->heard)())->toBe(($this->ids)($this->admin));
})->group('module:notifications', 'feature');

it('tells nobody about a count that needs neither a co-signature nor a decision', function () {
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('approved', $this->chef->id, ['varianceLines' => 0, 'items' => []]));
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('locked', $this->chef->id, ['varianceLines' => 0, 'items' => []], 63));

    expect(Notification::query()->count())->toBe(0);
})->group('module:notifications', 'feature');

it('writes one line per person per state: a redelivery adds none, the move to pending approval adds one', function () {
    $waiting = ($this->submitted)('awaiting_cosign', $this->chef->id);
    app(NotifyCountSubmitted::class)->handle($waiting);
    app(NotifyCountSubmitted::class)->handle($waiting);
    expect(Notification::query()->count())->toBe(3);

    $cosigned = ($this->submitted)('pending_approval', $this->chef->id, ['cosignedBy' => $this->overseer->id]);
    app(NotifyCountSubmitted::class)->handle($cosigned);
    app(NotifyCountSubmitted::class)->handle($cosigned);

    expect(Notification::query()->count())->toBe(5, 'the admin and the manager hear of it a second time, as a decision now due')
        ->and(Notification::query()->where('user_id', $this->admin->id)->orderBy('id')->pluck('dedupe_key')->all())->toBe(['count_submitted:61:awaiting_cosign', 'count_submitted:61:pending_approval']);
})->group('module:notifications', 'feature');

it('says a count that waits for a co-signature or a decision in both languages, warning when lines differ', function () {
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('awaiting_cosign', $this->chef->id));
    $line = Notification::query()->where('user_id', $this->overseer->id)->sole();

    expect($line->severity)->toBe('warning')
        ->and($line->title_en)->toBe('Please co-sign: Kitchen count')
        ->and($line->body_en)->toBe('Kitchen shelf, closing count: 1 of 4 lines differ. Flour -500 g.')
        ->and($line->title_am)->not->toBe($line->title_en)
        ->and($line->title_am)->toContain('ወጥ ቤት')
        ->and($line->body_am)->toContain('ዱቄት')->and($line->body_am)->toMatch('/\p{Ethiopic}/u')
        ->and($line->payload['caused_by_user_id'])->toBe($this->chef->id);

    Notification::query()->delete();
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('pending_approval', $this->chef->id));
    expect(Notification::query()->where('user_id', $this->admin->id)->sole()->title_en)->toBe('Approval needed: Kitchen count');
})->group('module:notifications', 'feature');

it('writes info, not warning, for a count without differences that still waits for a co-signature', function () {
    app(NotifyCountSubmitted::class)->handle(($this->submitted)('awaiting_cosign', $this->chef->id, ['varianceLines' => 0, 'items' => []]));

    $line = Notification::query()->where('user_id', $this->overseer->id)->sole();
    expect($line->severity)->toBe('info')
        ->and($line->body_en)->toBe('Kitchen shelf, closing count: all 4 lines match.');
})->group('module:notifications', 'feature');

it('lists up to five variance items in the body', function () {
    $items = [];
    foreach (range(1, 5) as $i) {
        $items[] = ['item_name_en' => "Item {$i}", 'item_name_am' => "እቃ {$i}", 'expected_qty' => '10.0000', 'counted_qty' => '9.5000', 'variance_qty' => '-0.5000', 'unit' => 'kg'];
    }
    $rendered = app(NotificationText::class)->render('count_submitted', ($this->submitted)('pending_approval', $this->chef->id, ['varianceLines' => 8, 'items' => $items])->payload());

    expect($rendered->body->en)->toContain('Item 1 -0.5 kg')->toContain('Item 5 -0.5 kg')->toContain('and 3 more')
        ->and($rendered->body->am)->toContain('እቃ 5');
})->group('module:notifications', 'unit');

it('tells the counter of an approval and of a rejection, nobody else', function () {
    app(NotifyVarianceDecided::class)->handle(NotificationEvents::varianceDecided($this->branch->id, $this->chef->id, $this->admin->id, 'approved'));
    $approved = Notification::query()->sole();
    expect($approved->user_id)->toBe($this->chef->id)
        ->and($approved->type)->toBe('variance_decided')
        ->and($approved->severity)->toBe('info')
        ->and($approved->title_en)->toBe('Count approved: Kitchen')
        ->and($approved->body_en)->toBe('Your closing count at Kitchen shelf was approved. Lines adjusted: 2.')
        ->and($approved->body_am)->toMatch('/\p{Ethiopic}/u')
        ->and($approved->dedupe_key)->toBe('variance_decided:61:approved')
        ->and($approved->payload['caused_by_user_id'])->toBe($this->admin->id);

    Notification::query()->delete();
    app(NotifyVarianceDecided::class)->handle(NotificationEvents::varianceDecided($this->branch->id, $this->chef->id, $this->manager->id, 'rejected'));
    $rejected = Notification::query()->sole();
    expect($rejected->user_id)->toBe($this->chef->id)
        ->and($rejected->severity)->toBe('warning')
        ->and($rejected->title_en)->toBe('Count rejected: Kitchen')
        ->and($rejected->body_en)->toContain('Count the top shelf again')->toContain('count again')
        ->and($rejected->body_am)->toMatch('/\p{Ethiopic}/u')
        ->and($rejected->dedupe_key)->toBe('variance_decided:61:rejected');
})->group('module:notifications', 'feature');

it('writes one decision line however often the event is delivered', function () {
    $event = NotificationEvents::varianceDecided($this->branch->id, $this->chef->id, $this->admin->id, 'approved');

    app(NotifyVarianceDecided::class)->handle($event);
    app(NotifyVarianceDecided::class)->handle($event);

    expect(Notification::query()->count())->toBe(1);
})->group('module:notifications', 'feature');

it('opens the decision on the screen of the station that counted', function (string $code, string $link) {
    app(NotifyVarianceDecided::class)->handle(NotificationEvents::varianceDecided($this->branch->id, $this->chef->id, $this->admin->id, 'approved', 61, ['stationCode' => $code, 'shiftId' => 9]));

    expect(Notification::query()->sole()->deep_link)->toBe($link);
})->with([
    ['kitchen', '/kds/kitchen?shift=9'],
    ['bar', '/kds/bar?shift=9'],
    ['till', '/till?shift=9'],
    ['cake_room', '/production?shift=9'],
    ['bakery', '/production?shift=9'],
    ['store', '/admin/inventory?shift=9'],
])->group('module:notifications', 'feature');

it('tells nobody of a decision on a person who is no longer active', function () {
    $this->chef->forceFill(['is_active' => false])->save();

    app(NotifyVarianceDecided::class)->handle(NotificationEvents::varianceDecided($this->branch->id, $this->chef->id, $this->admin->id));

    expect(Notification::query()->count())->toBe(0);
})->group('module:notifications', 'feature');

it('registers the two types with the channels of the plan', function () {
    expect(NotificationTypes::channels('count_submitted'))->toBe(['in_app', 'push', 'telegram'])
        ->and(NotificationTypes::channels('variance_decided'))->toBe(['in_app', 'push'])
        ->and(NotificationTypes::inboxKey('count_submitted', NotificationEvents::countSubmitted(1, 5, 'awaiting_cosign', 61)->payload()))->toBe('count_submitted:61:awaiting_cosign')
        ->and(NotificationTypes::inboxKey('variance_decided', NotificationEvents::varianceDecided(1, 5, 6, 'rejected', 61)->payload()))->toBe('variance_decided:61:rejected');
})->group('module:notifications', 'unit');

it('makes the Stations contract answer overseers for the listener', function () {
    expect(app(Stations::class)->isOverseer($this->overseer, $this->kitchen))->toBeTrue()
        ->and(app(Stations::class)->isOverseer($this->sous, $this->kitchen))->toBeFalse();
})->group('module:notifications', 'feature');
