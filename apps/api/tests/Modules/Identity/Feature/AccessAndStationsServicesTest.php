<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Resolvers\AssignedUsersResolver;
use App\Modules\Identity\Resolvers\NullShiftAttacher;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * The capabilities Catalog, Orders, Payments, Production, Notifications and Shifts will consume (dependency-graph.md):
 * can(user, permission, ?station), verifyPinFor(permission, pin), assignedUsers, canCount, isOverseer, and the two
 * interfaces with their Phase 2 default bindings (cycle 3): AssignedUsersResolver and NullShiftAttacher live here.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    withActor(makeUser($this->branch, 'admin')->id);
    $this->access = app(Access::class);
    $this->stations = app(Stations::class);
});

function assignTo(User $user, Station $station, bool $canCount = false, bool $isOverseer = false): void
{
    $user->stationAssignments()->create(['branch_id' => $station->branch_id, 'station_id' => $station->id, 'can_count' => $canCount, 'is_overseer' => $isOverseer]);
}

it('answers can() from the role defaults and per-user direct overrides', function () {
    $cashier = makeUser($this->branch, 'cashier');
    $waiter = makeUser($this->branch, 'waiter');

    expect($this->access->can($cashier, 'orders.create'))->toBeTrue()
        ->and($this->access->can($cashier, 'payments.take'))->toBeTrue()
        ->and($this->access->can($cashier, 'orders.void'))->toBeFalse()
        ->and($this->access->can($cashier, 'people.manage'))->toBeFalse()
        ->and($this->access->can($waiter, 'payments.take'))->toBeFalse();

    $cashier->givePermissionTo('orders.void');
    expect($this->access->can($cashier->fresh() ?? $cashier, 'orders.void'))->toBeTrue();
})->group('module:identity', 'feature');

it('scopes can() to a station: the permission plus an assignment there, or stations.all', function () {
    $chef = makeUser($this->branch, 'chef');
    $manager = makeUser($this->branch, 'manager');
    $waiter = makeUser($this->branch, 'waiter');
    assignTo($chef, $this->kitchen);
    assignTo($waiter, $this->kitchen);

    expect($this->access->can($chef, 'orders.accept'))->toBeTrue()
        ->and($this->access->can($chef, 'orders.accept', $this->kitchen))->toBeTrue()
        ->and($this->access->can($chef, 'orders.accept', $this->bar))->toBeFalse()
        ->and($this->access->can($manager, 'orders.accept', $this->bar))->toBeTrue()
        ->and($this->access->can($waiter, 'orders.accept', $this->kitchen))->toBeFalse();
})->group('module:identity', 'feature');

it('denies can() to an inactive user whatever the role', function () {
    $admin = makeUser($this->branch, 'admin', ['is_active' => false]);

    expect($this->access->can($admin, 'people.manage'))->toBeFalse();
})->group('module:identity', 'feature');

it('verifyPinFor() returns the user whose short code matches among those holding the permission', function () {
    $manager = makeUser($this->branch, 'manager');
    $cashier = makeUser($this->branch, 'cashier');
    app(ShortCodes::class)->set($manager, '2468');
    app(ShortCodes::class)->set($cashier, '1357');

    expect($this->access->verifyPinFor('orders.void', '2468')->id)->toBe($manager->id)
        ->and(fn () => $this->access->verifyPinFor('orders.void', '1357'))->toThrow(IdentityError::class, 'identity.pin.rejected')
        ->and(fn () => $this->access->verifyPinFor('orders.void', '0000'))->toThrow(IdentityError::class, 'identity.pin.rejected');
})->group('module:identity', 'feature');

it('verifyPinFor() ignores inactive holders and other branches', function () {
    $manager = makeUser($this->branch, 'manager', ['is_active' => false]);
    app(ShortCodes::class)->set($manager, '2468');
    $other = Branch::factory()->create();
    $foreign = makeUser($other, 'manager');
    actInBranch($other);
    app(ShortCodes::class)->set($foreign, '1122');
    actInBranch($this->branch);

    expect(fn () => $this->access->verifyPinFor('orders.void', '2468'))->toThrow(IdentityError::class)
        ->and(fn () => $this->access->verifyPinFor('orders.void', '1122'))->toThrow(IdentityError::class);
})->group('module:identity', 'feature');

it('verifyPinFor() locks the entry point after 5 wrong PINs and unlocks after 15 minutes', function () {
    $manager = makeUser($this->branch, 'manager');
    app(ShortCodes::class)->set($manager, '2468');
    withActor(makeUser($this->branch, 'cashier')->id);

    foreach (range(1, 5) as $i) {
        expect(fn () => $this->access->verifyPinFor('orders.void', '0000'))->toThrow(IdentityError::class, 'identity.pin.rejected');
    }
    expect(fn () => $this->access->verifyPinFor('orders.void', '2468'))->toThrow(IdentityError::class, 'identity.short_code.locked');

    $this->travel(16)->minutes();
    expect($this->access->verifyPinFor('orders.void', '2468')->id)->toBe($manager->id);
})->group('module:identity', 'feature');

it('assignedUsers() lists the active users assigned to the station and nobody else', function () {
    $chef = makeUser($this->branch, 'chef');
    $inactive = makeUser($this->branch, 'chef', ['is_active' => false]);
    $barista = makeUser($this->branch, 'barista');
    $gone = makeUser($this->branch, 'chef');
    assignTo($chef, $this->kitchen, canCount: true);
    assignTo($inactive, $this->kitchen, canCount: true);
    assignTo($barista, $this->bar, canCount: true);
    assignTo($gone, $this->kitchen);
    $gone->delete();

    expect($this->stations->assignedUsers($this->kitchen)->pluck('id')->all())->toBe([$chef->id])
        ->and($this->stations->assignedUsers($this->bar)->pluck('id')->all())->toBe([$barista->id]);
})->group('module:identity', 'feature');

it('canCount() and isOverseer() are per station flags on the assignment', function () {
    $chef = makeUser($this->branch, 'chef');
    $overseer = makeUser($this->branch, 'cashier');
    assignTo($chef, $this->kitchen, canCount: true, isOverseer: false);
    assignTo($overseer, $this->kitchen, canCount: false, isOverseer: true);
    assignTo($overseer, $this->bar, canCount: true, isOverseer: false);

    expect($this->stations->canCount($chef, $this->kitchen))->toBeTrue()
        ->and($this->stations->canCount($chef, $this->bar))->toBeFalse()
        ->and($this->stations->isOverseer($chef, $this->kitchen))->toBeFalse()
        ->and($this->stations->isOverseer($overseer, $this->kitchen))->toBeTrue()
        ->and($this->stations->isOverseer($overseer, $this->bar))->toBeFalse()
        ->and($this->stations->canCount($overseer, $this->bar))->toBeTrue();

    $overseer->forceFill(['is_active' => false])->save();
    expect($this->stations->isOverseer($overseer, $this->kitchen))->toBeFalse()
        ->and($this->stations->canCount($overseer, $this->bar))->toBeFalse();
})->group('module:identity', 'feature');

it('binds StationPresenceResolver to AssignedUsersResolver, whose onDuty() is assignedUsers()', function () {
    $chef = makeUser($this->branch, 'chef');
    assignTo($chef, $this->kitchen);

    $resolver = app(StationPresenceResolver::class);
    expect($resolver)->toBeInstanceOf(AssignedUsersResolver::class)
        ->and($resolver->onDuty($this->kitchen)->pluck('id')->all())->toBe([$chef->id])
        ->and($resolver->onDuty($this->bar)->all())->toBe([]);
})->group('module:identity', 'feature');

it('binds ShiftAttacher to NullShiftAttacher, which attaches no shift until Phase 3 rebinds', function () {
    $chef = makeUser($this->branch, 'chef');

    $attacher = app(ShiftAttacher::class);
    expect($attacher)->toBeInstanceOf(NullShiftAttacher::class)
        ->and($attacher->attach($chef, $this->kitchen))->toBeNull();
})->group('module:identity', 'feature');
