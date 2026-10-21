<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\People;
use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

pest()->group('module:identity', 'feature');

/*
 * The overseer flag as other modules ask for it (step 2.7c, additive provider changes): People::isOverseer(userId, station) for
 * Production's confirm rule, and Recipients::overseers(station) for Notifications' "please sign this off". Only an active
 * person of the branch who is assigned to that very station with is_overseer counts; a flag at another station, a removed
 * assignment, an inactive or deleted user, and another branch's user never do. Results are in id order, each person once.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->branch->id]);
    $this->flag = function (string $role, Station $station, bool $overseer = true, array $user = []) {
        $person = makeUser($this->branch, $role, $user);
        UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $person->id, 'station_id' => $station->id, 'is_overseer' => $overseer]);

        return $person;
    };
});

it('says whether a person is the overseer flagged at a station', function () {
    $overseer = ($this->flag)('cake_baker', $this->cakeRoom);
    $plain = ($this->flag)('cake_baker', $this->cakeRoom, false);
    $elsewhere = ($this->flag)('bread_baker', $this->bakery);
    $people = app(People::class);

    expect($people->isOverseer($overseer->id, $this->cakeRoom))->toBeTrue()
        ->and($people->isOverseer($plain->id, $this->cakeRoom))->toBeFalse('assigned, not flagged')
        ->and($people->isOverseer($elsewhere->id, $this->cakeRoom))->toBeFalse('flagged at another station')
        ->and($people->isOverseer($elsewhere->id, $this->bakery))->toBeTrue()
        ->and($people->isOverseer(987654, $this->cakeRoom))->toBeFalse();
});

it('says no for an inactive, deleted or foreign person, and for a removed assignment', function () {
    $inactive = ($this->flag)('cake_baker', $this->cakeRoom, true, ['is_active' => false]);
    $deleted = ($this->flag)('cake_baker', $this->cakeRoom);
    $deleted->delete();
    $removed = ($this->flag)('cake_baker', $this->cakeRoom);
    UserStationAssignment::query()->where('user_id', $removed->id)->delete();
    $stranger = makeUser(Branch::factory()->create(), 'cake_baker');
    $people = app(People::class);

    foreach ([$inactive, $deleted, $removed, $stranger] as $person) {
        expect($people->isOverseer($person->id, $this->cakeRoom))->toBeFalse();
    }
});

it('lists the overseers of one station, in id order, active and of this branch only', function () {
    $first = ($this->flag)('cake_baker', $this->cakeRoom);
    ($this->flag)('cake_baker', $this->cakeRoom, false);
    ($this->flag)('bread_baker', $this->bakery);
    ($this->flag)('cake_baker', $this->cakeRoom, true, ['is_active' => false]);
    $second = ($this->flag)('manager', $this->cakeRoom);

    $ids = app(Recipients::class)->overseers($this->cakeRoom)->pluck('id')->all();

    expect($ids)->toBe([$first->id, $second->id])
        ->and(app(Recipients::class)->overseers($this->bakery)->count())->toBe(1);
});
