<?php

declare(strict_types=1);

use App\Modules\Identity\Broadcasting\ChannelRules;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Models\User;
use App\Shared\Enums\StationCode;
use App\Shared\Live\Channels;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Who may follow which live channel (ADR 0009 decision 5), with the same rules the endpoints use (Identity's Access):
 *   station.{id}          orders.accept at that station: an assignment there, or stations.all (admin, manager);
 *                         never a station of another branch
 *   user.{id}             that user only
 *   branch.{id}.stock     the user's own branch, with inventory.view (the floor roles, not the waiter)
 *   branch.{id}.orders    the user's own branch, for those who see every order of it (payments.take or stations.all,
 *                         as GET /orders: admin, manager, cashier)
 * The rules live in Identity (Shared stays a leaf); the registry of names lives in Shared (Channels). Only private
 * channels exist: a public or presence name, or one the registry does not know, is refused for everyone.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $this->theirKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->other->id]);
    $this->rules = app(ChannelRules::class);

    // one user per role; the station roles are assigned where they work, the others to the kitchen as well, so an
    // assignment alone (without orders.accept) is shown not to be enough
    $this->users = [];
    foreach (PermissionRegistry::ROLES as $role) {
        $user = makeUser($this->branch, $role);
        $station = match ($role) {
            'admin', 'manager' => null,
            'barista' => $this->bar,
            default => $this->kitchen,
        };
        if ($station !== null) {
            $user->stationAssignments()->create(['branch_id' => $this->branch->id, 'station_id' => $station->id, 'can_count' => false, 'is_overseer' => false]);
        }
        $this->users[$role] = $user->fresh() ?? $user;
    }
    $this->stranger = makeUser($this->other, 'waiter');

    $this->subscription = fn (string $channel, User $as): string => 'private-'.match ($channel) {
        'station:kitchen' => Channels::station($this->kitchen->id),
        'station:bar' => Channels::station($this->bar->id),
        'station:theirs' => Channels::station($this->theirKitchen->id),
        'user:self' => Channels::user($as->id),
        'user:other' => Channels::user($this->stranger->id),
        'stock:own' => Channels::branchStock($this->branch->id),
        'stock:other' => Channels::branchStock($this->other->id),
        'orders:own' => Channels::branchOrders($this->branch->id),
        'orders:other' => Channels::branchOrders($this->other->id),
    };
});

it('allows and refuses each channel per role and branch', function (string $channel, array $allowed) {
    foreach (PermissionRegistry::ROLES as $role) {
        $user = $this->users[$role];
        $name = ($this->subscription)($channel, $user);

        expect($this->rules->allows($user, $name))->toBe(in_array($role, $allowed, true), "{$role} on {$name}");
    }
})->with([
    'the kitchen station' => ['station:kitchen', ['admin', 'manager', 'chef']],
    'the bar station' => ['station:bar', ['admin', 'manager', 'barista']],
    "another branch's station" => ['station:theirs', []],
    'their own user channel' => ['user:self', PermissionRegistry::ROLES],
    "someone else's user channel" => ['user:other', []],
    "their branch's stock" => ['stock:own', ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    "another branch's stock" => ['stock:other', []],
    "their branch's orders" => ['orders:own', ['admin', 'manager', 'cashier']],
    "another branch's orders" => ['orders:other', []],
])->group('module:shared', 'module:identity', 'feature');

it('lets the other branch follow its own channels and none of ours', function () {
    $manager = makeUser($this->other, 'manager');

    expect($this->rules->allows($manager, 'private-'.Channels::station($this->theirKitchen->id)))->toBeTrue()
        ->and($this->rules->allows($manager, 'private-'.Channels::branchStock($this->other->id)))->toBeTrue()
        ->and($this->rules->allows($manager, 'private-'.Channels::branchOrders($this->other->id)))->toBeTrue()
        ->and($this->rules->allows($manager, 'private-'.Channels::station($this->kitchen->id)))->toBeFalse()
        ->and($this->rules->allows($manager, 'private-'.Channels::branchStock($this->branch->id)))->toBeFalse()
        ->and($this->rules->allows($manager, 'private-'.Channels::branchOrders($this->branch->id)))->toBeFalse();
})->group('module:shared', 'module:identity', 'feature');

it('needs the assignment: a chef who works nowhere does not follow the kitchen', function () {
    $chef = makeUser($this->branch, 'chef');

    expect($this->rules->allows($chef, 'private-'.Channels::station($this->kitchen->id)))->toBeFalse();

    $chef->stationAssignments()->create(['branch_id' => $this->branch->id, 'station_id' => $this->kitchen->id, 'can_count' => false, 'is_overseer' => false]);
    expect($this->rules->allows($chef->fresh() ?? $chef, 'private-'.Channels::station($this->kitchen->id)))->toBeTrue();
})->group('module:shared', 'module:identity', 'feature');

it('follows the permission, not the role: a waiter granted orders.accept follows the station they are assigned to', function () {
    $waiter = $this->users['waiter'];
    expect($this->rules->allows($waiter, 'private-'.Channels::station($this->kitchen->id)))->toBeFalse();

    $waiter->givePermissionTo('orders.accept');
    $waiter = $waiter->fresh() ?? $waiter;

    expect($this->rules->allows($waiter, 'private-'.Channels::station($this->kitchen->id)))->toBeTrue()
        ->and($this->rules->allows($waiter, 'private-'.Channels::station($this->bar->id)))->toBeFalse();
})->group('module:shared', 'module:identity', 'feature');

it('refuses a switched-off user everything, their own channel included', function () {
    $chef = $this->users['chef'];
    $chef->forceFill(['is_active' => false])->save();
    $chef = $chef->fresh() ?? $chef;

    expect($this->rules->allows($chef, 'private-'.Channels::user($chef->id)))->toBeFalse()
        ->and($this->rules->allows($chef, 'private-'.Channels::station($this->kitchen->id)))->toBeFalse()
        ->and($this->rules->allows($chef, 'private-'.Channels::branchStock($this->branch->id)))->toBeFalse();
})->group('module:shared', 'module:identity', 'feature');

it('refuses a station that does not exist or was removed', function () {
    $admin = $this->users['admin'];
    $this->bar->delete();

    expect($this->rules->allows($admin, 'private-'.Channels::station($this->bar->id)))->toBeFalse()
        ->and($this->rules->allows($admin, 'private-'.Channels::station(999999999)))->toBeFalse()
        ->and($this->rules->allows($admin, 'private-'.Channels::station($this->kitchen->id)))->toBeTrue();
})->group('module:shared', 'module:identity', 'feature');

it('refuses any name that is not a private channel of the registry', function (string $name) {
    expect($this->rules->allows($this->users['admin'], $name))->toBeFalse();
})->with([
    'public channel' => ['station.1'],
    'presence channel' => ['presence-station.1'],
    'unknown channel' => ['private-orders.1'],
    'malformed id' => ['private-station.abc'],
    'empty' => [''],
])->group('module:shared', 'module:identity', 'feature');
