<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Modules\Notifications\Listeners\NotifyStockOut;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Tests\Support\NotificationDrivers;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b (plan section 5): who is reached outside the app. The inbox tells everyone the listener picked; push and Telegram
 * go to the people who can act on it from anywhere. A stock-out of an INGREDIENT goes to the roles (admin, manager) and to the
 * people assigned to the station of the place, "in-app only for the latter": the chef sees the line in the bell, the owner's
 * phone buzzes. A stock-out of a product is the producing station's job, so its people are reached on every channel.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    NotificationDrivers::array();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->chef = makeUser($this->branch, 'chef');
    $this->baker = makeUser($this->branch, 'cake_baker');
    withActor($this->admin->id);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    app(Stations::class)->replace($this->chef, [['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false]]);
    app(Stations::class)->replace($this->baker, [['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false]]);
    foreach ([$this->admin, $this->manager, $this->chef, $this->baker] as $person) {
        NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $person->id]);
    }
    $this->reached = fn (): array => NotificationDelivery::query()->with('notification')->get()->map(fn (NotificationDelivery $d): int => $d->notification->user_id)->sort()->values()->all();
});

it('reaches the roles outside the app for an ingredient stock-out, and the station people in the inbox only', function () {
    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 12, 2, 31, [
        'kind' => 'ingredient', 'fulfilment' => 'bought_in', 'stationId' => $this->kitchen->id, 'itemEn' => 'Flour', 'itemAm' => 'ዱቄት',
    ]));

    $heard = Notification::query()->pluck('user_id')->sort()->values()->all();
    expect($heard)->toBe(collect([$this->admin, $this->manager, $this->chef])->map->id->sort()->values()->all(), 'the inbox tells all three')
        ->and(($this->reached)())->toBe(collect([$this->admin, $this->manager])->map->id->sort()->values()->all(), 'push reaches the two roles, not the chef');
});

it('reaches the producing station outside the app for a product stock-out', function () {
    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 11, 4, 21, [
        'kind' => 'product', 'stationId' => $this->kitchen->id, 'productionStationId' => $this->cakeRoom->id,
    ]));

    expect(($this->reached)())->toBe(collect([$this->admin, $this->manager, $this->baker])->map->id->sort()->values()->all());
});

it('records nothing outside the app while every external driver is off, whatever endpoints exist', function () {
    NotificationDrivers::allOff();

    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 12, 2, 31, ['kind' => 'ingredient', 'fulfilment' => 'bought_in', 'stationId' => $this->kitchen->id]));

    expect(Notification::query()->count())->toBe(3)->and(NotificationDelivery::query()->count())->toBe(0);
});
