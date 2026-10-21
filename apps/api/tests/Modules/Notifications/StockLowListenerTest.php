<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Listeners\ClearStockDedupe;
use App\Modules\Notifications\Listeners\NotifyStockLow;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Notifications\Support\Dedupe;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationDrivers;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.6a (plan sections 5 and 7; ADR 0011): StockLow -> the inbox. Who hears of it: a PRODUCT that runs low goes to the people on
 * duty at the station that makes it, the production.confirm holders and the roles the rule names; an INGREDIENT to the roles the
 * rule names and, when the rule lists the literal `station`, the people assigned to the station of the place, in the inbox only.
 * The delivery gate is per item and place (stock_low:{item}:{location}): people are told once per episode, however often the
 * event is delivered, and told again after the recovery. A low alert does not borrow the stock-out gate and vice versa.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->chef = makeUser($this->branch, 'chef');
    $this->baker = makeUser($this->branch, 'cake_baker');
    $this->breadBaker = makeUser($this->branch, 'bread_baker');
    withActor($this->admin->id);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $assign = fn (User $user, Station $station) => app(Stations::class)->replace($user, [['station_id' => $station->id, 'can_count' => true, 'is_overseer' => false]]);
    $assign($this->baker, $this->cakeRoom);
    $assign($this->breadBaker, $this->bakery);
    $assign($this->chef, $this->kitchen);
    $this->heard = fn () => Notification::query()->where('type', 'stock_low')->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (User ...$users) => collect($users)->map(fn (User $u): int => $u->id)->sort()->values()->all();
});

it('is queued after commit on the default queue and registered for StockLow', function () {
    Queue::fake();

    event(NotificationEvents::stockLow($this->branch->id));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyStockLow::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull('the default queue: the cron worker drains only that one')
        ->and(is_subclass_of(NotifyStockLow::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyStockLow::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and((new NotifyStockLow(app(\App\Modules\Notifications\Support\EventScope::class), app(\App\Modules\Identity\Contracts\Recipients::class), app(\App\Modules\Notifications\Contracts\Notifier::class)))->tries)->toBe(3);
    expect(Notification::query()->count())->toBe(0, 'nothing is written in the request that dispatched the event');
});

it('tells the roles the rule names for an ingredient, and nobody else when the rule does not list the station', function () {
    app(NotifyStockLow::class)->handle(NotificationEvents::stockLow($this->branch->id, 12, 2, 31, ['stationId' => $this->kitchen->id, 'roles' => ['admin', 'manager']]));

    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->manager), 'the chef works at the place but the rule does not list the station');

    $line = Notification::query()->where('user_id', $this->admin->id)->sole();
    expect($line->severity)->toBe('warning')
        ->and($line->title_en)->toBe('Running low: Flour')
        ->and($line->body_am)->toBe('በመጋዘን 4000 g ብቻ ቀርቷል (ዝቅተኛው 5000 g)። 25000 g ያዝዙ።')
        ->and($line->deep_link)->toBe('/inventory?item=12&location=2')
        ->and($line->dedupe_key)->toBe('stock_low:12:2:31')
        ->and($line->payload)->toMatchArray(['item_id' => 12, 'location_id' => 2, 'alert_id' => 31, 'min_qty' => '5000.0000', 'reorder_qty' => '25000.0000', 'notify_roles' => ['admin', 'manager']]);
});

it('adds the people of the station of the place for an ingredient when the rule lists the station, and only a role that exists', function () {
    app(NotifyStockLow::class)->handle(NotificationEvents::stockLow($this->branch->id, 12, 2, 31, ['stationId' => $this->kitchen->id, 'roles' => ['manager', 'station']]));

    expect(($this->heard)())->toBe(($this->ids)($this->manager, $this->chef), 'the manager by role, the chef by the station; the admin was not listed');
});

it('tells a product that runs low to the station that makes it, the confirmers and the roles of the rule', function () {
    app(NotifyStockLow::class)->handle(NotificationEvents::stockLow($this->branch->id, 11, 4, 32, [
        'kind' => 'product', 'fulfilment' => 'to_stock', 'stationId' => $this->bakery->id, 'productionStationId' => $this->cakeRoom->id, 'roles' => ['cashier'],
    ]));

    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->manager, $this->baker, $this->cashier), 'production.confirm holders, the cake room baker and the cashier the rule names; not the bread baker of the place, not the chef');
});

it('reaches the roles outside the app and the station-only people in the inbox only', function () {
    NotificationDrivers::array();
    foreach ([$this->admin, $this->manager, $this->chef] as $person) {
        NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $person->id]);
    }

    app(NotifyStockLow::class)->handle(NotificationEvents::stockLow($this->branch->id, 12, 2, 31, ['stationId' => $this->kitchen->id, 'roles' => ['admin', 'manager', 'station']]));

    $reached = NotificationDelivery::query()->with('notification')->get()->map(fn (NotificationDelivery $d): int => $d->notification->user_id)->sort()->values()->all();
    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->manager, $this->chef))
        ->and($reached)->toBe(($this->ids)($this->admin, $this->manager), 'the chef sees it in the bell, the owner phone buzzes');
});

it('writes one line per person however often the event is delivered, and one gate per item and place', function () {
    $event = NotificationEvents::stockLow($this->branch->id, 12, 2, 31);

    app(NotifyStockLow::class)->handle($event);
    app(NotifyStockLow::class)->handle($event);
    app(NotifyStockLow::class)->handle($event);

    expect(Notification::query()->where('type', 'stock_low')->count())->toBe(2)
        ->and(DB::table('notification_dedupe')->where('dedupe_key', 'stock_low:12:2')->where('is_active', 1)->count())->toBe(1);
});

it('holds a second low alert until the recovery, then tells again with a new line', function () {
    $low = fn (int $alert) => NotificationEvents::stockLow($this->branch->id, 12, 2, $alert);

    app(NotifyStockLow::class)->handle($low(31));
    app(NotifyStockLow::class)->handle($low(32)); // another episode before the recovery was heard: the gate holds
    expect(Notification::query()->count())->toBe(2, 'the gate holds the second episode until the recovery was heard');

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($this->branch->id, 12, 2, 'low', 31));
    app(NotifyStockLow::class)->handle($low(32));

    expect(Notification::query()->where('dedupe_key', 'stock_low:12:2:32')->count())->toBe(2)
        ->and(app(Dedupe::class)->isActive('stock_low:12:2'))->toBeTrue();
});

it('keeps the low gate and the out gate apart: a stock-out does not use up a low alert, a recovery from out does not re-arm it', function () {
    app(NotifyStockLow::class)->handle(NotificationEvents::stockLow($this->branch->id, 12, 2, 31));
    app(\App\Modules\Notifications\Listeners\NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 12, 2, 41, ['kind' => 'ingredient', 'fulfilment' => 'bought_in']));

    expect(Notification::query()->where('type', 'stock_low')->count())->toBe(2)
        ->and(Notification::query()->where('type', 'stock_out')->count())->toBe(2);

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($this->branch->id, 12, 2, 'out', 41));
    $gate = app(Dedupe::class);
    expect($gate->isActive('stock_out:12:2'))->toBeFalse()->and($gate->isActive('stock_low:12:2'))->toBeTrue();

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($this->branch->id, 12, 2, 'low', 31));
    expect($gate->isActive('stock_low:12:2'))->toBeFalse();
});

it('runs as the person whose movement started the episode and tells nobody of another branch', function () {
    $other = Branch::factory()->create();
    $stranger = makeUser($other, 'admin');
    app(NotifyStockLow::class)->handle(NotificationEvents::stockLow($this->branch->id, 12, 2, 31, ['causedBy' => $this->cashier->id]));

    expect(Notification::query()->first()->payload['caused_by_user_id'])->toBe($this->cashier->id)
        ->and(Notification::query()->withoutGlobalScopes()->where('user_id', $stranger->id)->count())->toBe(0);
});
