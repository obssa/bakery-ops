<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\People;
use App\Modules\Identity\Contracts\Stations;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

pest()->group('module:identity', 'feature');

/*
 * People::workStationIds (step 3.2b, an additive capability like People::can): the ids of the stations a person may work at, for
 * Shifts' lists (GET /shifts shows the stations the caller works at). Their live assignments, or every station of the branch for
 * a person who holds stations.all; nothing for someone inactive, deleted, unknown or of another branch.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $this->branch->id]);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    withActor(makeUser($this->branch, 'admin')->id);
});

it('answers the assigned stations, every station for stations.all, and nothing for people who cannot act', function () {
    $cashier = makeUser($this->branch, 'cashier');
    $chef = makeUser($this->branch, 'chef');
    $manager = makeUser($this->branch, 'manager');
    app(Stations::class)->replace($cashier, [['station_id' => $this->till->id, 'can_count' => true, 'is_overseer' => false], ['station_id' => $this->bar->id, 'can_count' => false, 'is_overseer' => false]]);
    $people = app(People::class);
    $all = [$this->till->id, $this->kitchen->id, $this->bar->id];
    sort($all);

    $mine = $people->workStationIds($cashier->id);
    sort($mine);
    $everything = $people->workStationIds($manager->id);
    sort($everything);
    expect($mine)->toBe([$this->till->id, $this->bar->id])
        ->and($everything)->toBe($all)
        ->and($people->workStationIds($chef->id))->toBe([])
        ->and($people->workStationIds(987654))->toBe([]);

    $cashier->forceFill(['is_active' => false])->save();
    expect($people->workStationIds($cashier->id))->toBe([]);
    $foreign = makeUser(Branch::factory()->create(), 'manager');
    expect($people->workStationIds($foreign->id))->toBe([]);
});
