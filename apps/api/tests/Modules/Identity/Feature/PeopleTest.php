<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\People;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

pest()->group('module:identity', 'feature');

/*
 * Identity\Contracts\People (step 2.7b, an additive provider change): the questions Production asks about a person by id,
 * without importing the model. can(): is this an active person of this branch who holds the permission (at the station, when
 * one is given: assigned there, or working at every station). attachShift(): ShiftAttacher::attach for that person, the shift
 * their writes should now carry (null until Phase 3). names(): the display names of the ids, for a card that names a requester
 * or a baker who has since left, so inactive and deleted people are named too, but never another branch's. A final class
 * resolved per use (it carries the scoped CurrentBranch).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->people = app(People::class);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->branch->id]);
    $this->baker = makeUser($this->branch, 'cake_baker');
    UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->baker->id, 'station_id' => $this->cakeRoom->id]);
});

it('says whether an active person of the branch holds a permission, at a station when one is given', function () {
    $admin = makeUser($this->branch, 'admin');
    $cashier = makeUser($this->branch, 'cashier');

    expect($this->people->can($this->baker->id, 'production.fulfil'))->toBeTrue()
        ->and($this->people->can($this->baker->id, 'production.fulfil', $this->cakeRoom))->toBeTrue()
        ->and($this->people->can($this->baker->id, 'production.fulfil', $this->bakery))->toBeFalse('assigned to another station')
        ->and($this->people->can($this->baker->id, 'production.plan'))->toBeFalse('does not hold the permission')
        ->and($this->people->can($admin->id, 'production.fulfil', $this->bakery))->toBeTrue('the admin works at every station')
        ->and($this->people->can($cashier->id, 'production.fulfil'))->toBeFalse();
});

it('says no for anyone who cannot act: inactive, deleted, another branch, unknown', function () {
    $inactive = makeUser($this->branch, 'cake_baker', ['is_active' => false]);
    $deleted = makeUser($this->branch, 'cake_baker');
    $deleted->delete();
    $stranger = makeUser(Branch::factory()->create(), 'cake_baker');

    foreach ([$inactive->id, $deleted->id, $stranger->id, 987654] as $id) {
        expect($this->people->can($id, 'production.fulfil'))->toBeFalse();
    }
});

it('attaches a shift through the bound ShiftAttacher: null by default, the answer of a rebinding, nothing for a person who cannot act', function () {
    expect($this->people->attachShift($this->baker->id, $this->cakeRoom))->toBeNull();

    $calls = new ArrayObject;
    $this->app->bind(ShiftAttacher::class, fn () => new class($calls) implements ShiftAttacher
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function attach(User $user, Station $station): ?int
        {
            $this->calls[] = [$user->id, $station->id];

            return 91;
        }
    });
    $inactive = makeUser($this->branch, 'cake_baker', ['is_active' => false]);

    expect(app(People::class)->attachShift($this->baker->id, $this->cakeRoom))->toBe(91)
        ->and(app(People::class)->attachShift($inactive->id, $this->cakeRoom))->toBeNull()
        ->and(app(People::class)->attachShift(987654, $this->cakeRoom))->toBeNull()
        ->and($calls->getArrayCopy())->toBe([[$this->baker->id, $this->cakeRoom->id]]);
});

it('names people by id, the ones who have left included, and never another branch', function () {
    $a = makeUser($this->branch, 'cashier', ['name' => 'Abebe Kebede']);
    $gone = makeUser($this->branch, 'cake_baker', ['name' => 'Selam Tesfaye', 'is_active' => false]);
    $deleted = makeUser($this->branch, 'chef', ['name' => 'Dawit Haile']);
    $deleted->delete();
    $stranger = makeUser(Branch::factory()->create(), 'cashier', ['name' => 'Someone Else']);

    $names = $this->people->names([$a->id, $gone->id, $deleted->id, $stranger->id, 987654, $a->id]);

    expect($names)->toBe([$a->id => 'Abebe Kebede', $gone->id => 'Selam Tesfaye', $deleted->id => 'Dawit Haile'])
        ->and($this->people->names([]))->toBe([]);
});

it('is a final class built per use, never a singleton', function () {
    expect((new ReflectionClass(People::class))->isFinal())->toBeTrue()
        ->and(app(People::class))->not->toBe(app()->make(People::class));
});
