<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Listeners\NotifyProductionAssigned;
use App\Modules\Notifications\Listeners\NotifyProductionRequested;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.7b (phase-2 plan section 5): the Notifications listeners of the two production events. A new request is told to the
 * people on duty at the station that makes the product (Identity's Recipients::onDuty: the roster once Shifts exists, the
 * people assigned to the station until then), not to the person who asked; an assignment is told to the baker it names, not to
 * the planner who made it. Each line is keyed so a redelivered event writes nothing twice: one line per request, and one per
 * assignment (the request version after the write), so naming the same baker again later is heard again. Both run as the
 * person who caused the event in the event's branch (Support\EventScope). The queueing itself is pinned in ListenersTest.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->baker = makeUser($this->branch, 'cake_baker');
    $this->baker2 = makeUser($this->branch, 'cake_baker');
    $this->breadBaker = makeUser($this->branch, 'bread_baker');
    withActor($this->admin->id);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $assign = fn (User $user, Station $station) => app(Stations::class)->replace($user, [['station_id' => $station->id, 'can_count' => true, 'is_overseer' => false]]);
    $assign($this->baker, $this->cakeRoom);
    $assign($this->baker2, $this->cakeRoom);
    $assign($this->breadBaker, $this->bakery);
    $this->heard = fn (string $type) => Notification::query()->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (User ...$users) => collect($users)->map(fn (User $u): int => $u->id)->sort()->values()->all();
});

it('tells a new request to the people at the station that makes it and nobody else', function () {
    app(NotifyProductionRequested::class)->handle(NotificationEvents::productionRequested($this->branch->id, $this->cashier->id, 51, ['stationId' => $this->cakeRoom->id]));

    expect(($this->heard)('production_requested'))->toBe(($this->ids)($this->baker, $this->baker2), 'not the bread baker, not the admin or the manager, not the cashier who asked');
    $line = Notification::query()->where('user_id', $this->baker->id)->sole();
    expect($line->severity)->toBe('info')
        ->and($line->title_en)->toBe('Please make: Black forest cake')
        ->and($line->body_en)->toBe('12 pcs of Black forest cake requested at Cake room.')
        ->and($line->title_am)->toBe('እባክዎ ያዘጋጁ፡ ብላክ ፎረስት ኬክ')
        ->and($line->deep_link)->toBe('/production?request=51')
        ->and($line->dedupe_key)->toBe('production_requested:51')
        ->and($line->payload['request_id'])->toBe(51)
        ->and($line->payload['caused_by_user_id'])->toBe($this->cashier->id);
});

it('does not tell the person who asked, even when they work at the station', function () {
    app(NotifyProductionRequested::class)->handle(NotificationEvents::productionRequested($this->branch->id, $this->baker2->id, 52, ['stationId' => $this->cakeRoom->id]));

    expect(($this->heard)('production_requested'))->toBe(($this->ids)($this->baker));
});

it('says a stock-out request in its own words', function () {
    app(NotifyProductionRequested::class)->handle(NotificationEvents::productionRequested($this->branch->id, $this->cashier->id, 53, ['stationId' => $this->cakeRoom->id, 'reason' => 'stock_out', 'qty' => '40.0000']));

    expect(Notification::query()->where('user_id', $this->baker->id)->sole()->body_en)->toBe('Black forest cake ran out. Make 40 pcs at Cake room.');
});

it('writes one line per person however often the event is delivered, and a new request is a new line', function () {
    $event = NotificationEvents::productionRequested($this->branch->id, $this->cashier->id, 54, ['stationId' => $this->cakeRoom->id]);

    app(NotifyProductionRequested::class)->handle($event);
    app(NotifyProductionRequested::class)->handle($event);
    expect(Notification::query()->where('type', 'production_requested')->count())->toBe(2);

    app(NotifyProductionRequested::class)->handle(NotificationEvents::productionRequested($this->branch->id, $this->cashier->id, 55, ['stationId' => $this->cakeRoom->id]));
    expect(Notification::query()->where('type', 'production_requested')->count())->toBe(4);
});

it('tells nobody when the station is gone or when nobody works there', function () {
    $empty = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);

    app(NotifyProductionRequested::class)->handle(NotificationEvents::productionRequested($this->branch->id, $this->cashier->id, 56, ['stationId' => 987654]));
    app(NotifyProductionRequested::class)->handle(NotificationEvents::productionRequested($this->branch->id, $this->cashier->id, 57, ['stationId' => $empty->id]));

    expect(Notification::query()->count())->toBe(0);
});

it('tells an assignment to the baker it names, not to the planner', function () {
    app(NotifyProductionAssigned::class)->handle(NotificationEvents::productionAssigned($this->branch->id, $this->baker->id, $this->manager->id, 51, ['stationId' => $this->cakeRoom->id]));

    $line = Notification::query()->sole();
    expect($line->user_id)->toBe($this->baker->id)
        ->and($line->type)->toBe('production_assigned')
        ->and($line->severity)->toBe('info')
        ->and($line->title_en)->toBe('Assigned to you: Black forest cake')
        ->and($line->body_en)->toBe('Make 12 pcs of Black forest cake at Cake room.')
        ->and($line->body_am)->toBe('12 pcs ብላክ ፎረስት ኬክ በየኬክ ክፍል ያዘጋጁ።')
        ->and($line->deep_link)->toBe('/production?request=51')
        ->and($line->dedupe_key)->toBe('production_assigned:51:2')
        ->and($line->payload['caused_by_user_id'])->toBe($this->manager->id, 'the planner is the cause');
});

it('does not tell a planner who assigned the request to themselves', function () {
    app(NotifyProductionAssigned::class)->handle(NotificationEvents::productionAssigned($this->branch->id, $this->admin->id, $this->admin->id, 58));

    expect(Notification::query()->count())->toBe(0);
});

it('hears each assignment once: a redelivery writes nothing, a later assignment of the same baker is heard again', function () {
    $first = NotificationEvents::productionAssigned($this->branch->id, $this->baker->id, $this->manager->id, 59, ['version' => 2]);
    app(NotifyProductionAssigned::class)->handle($first);
    app(NotifyProductionAssigned::class)->handle($first);
    expect(Notification::query()->count())->toBe(1);

    app(NotifyProductionAssigned::class)->handle(NotificationEvents::productionAssigned($this->branch->id, $this->baker2->id, $this->manager->id, 59, ['version' => 3]));
    app(NotifyProductionAssigned::class)->handle(NotificationEvents::productionAssigned($this->branch->id, $this->baker->id, $this->manager->id, 59, ['version' => 4]));

    expect(Notification::query()->where('user_id', $this->baker->id)->count())->toBe(2)
        ->and(Notification::query()->where('user_id', $this->baker2->id)->count())->toBe(1);
});

it('tells nobody when the baker is no longer active, and nobody of another branch', function () {
    $this->baker->forceFill(['is_active' => false])->save();
    app(NotifyProductionAssigned::class)->handle(NotificationEvents::productionAssigned($this->branch->id, $this->baker->id, $this->manager->id, 60));

    $stranger = makeUser(Branch::factory()->create(), 'cake_baker');
    app(NotifyProductionAssigned::class)->handle(NotificationEvents::productionAssigned($this->branch->id, $stranger->id, $this->manager->id, 61));

    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(0);
});
