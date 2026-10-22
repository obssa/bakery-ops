<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Resolvers\AssignedUsersResolver;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Contract: production -> identity (manifest contracts["production->identity"]; phase-2 plan section 2 and 10.5;
 * dependency-graph.md, cycle 3).
 *
 * Written BEFORE any code exists in App\Modules\Production (step 2.1): only Identity classes and factories. Phrased from
 * Production's side: "I route a request to the people assigned to the producing station and ask who is on duty through
 * an interface I can be rebound under; the baker may fulfil at their own station only; a second person confirms, an
 * overseer of the station counts as one; a shift is attached through an interface that answers nothing until Phase 3".
 * It runs in Identity's CI job (mod-identity) for the life of the project.
 */

/** A roster-style presence resolver, the shape Phase 3's Shifts will bind: only the people the roster names are on duty. */
function rosterResolver(array $userIds): StationPresenceResolver
{
    return new class($userIds) implements StationPresenceResolver
    {
        /** @param list<int> $userIds */
        public function __construct(private readonly array $userIds) {}

        public function onDuty(Station $station): Collection
        {
            return app(Stations::class)->assignedUsers($station)->filter(fn (User $u): bool => in_array($u->id, $this->userIds, true))->values();
        }
    };
}

beforeEach(function () {
    seedRoles();
    $this->other = Branch::factory()->create();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $this->people = [];
    foreach (PermissionRegistry::ROLES as $role) {
        $this->people[$role] = makeUser($this->branch, $role);
    }
    withActor($this->people['admin']->id);
    $this->stations = app(Stations::class);
    $this->assign = fn (User $user, Station $station, bool $overseer = false) => $this->stations->replace($user, [['station_id' => $station->id, 'can_count' => true, 'is_overseer' => $overseer]]);
});

it('lists assignedUsers(station) without inactive users, soft-deleted users and users of another branch', function () {
    $second = makeUser($this->branch, 'cake_baker');
    $inactive = makeUser($this->branch, 'cake_baker');
    $gone = makeUser($this->branch, 'cake_baker');
    foreach ([$this->people['cake_baker'], $second, $inactive, $gone] as $user) {
        ($this->assign)($user, $this->cakeRoom);
    }
    // a user of another branch holding an assignment row to this branch's station (a data error, never an answer)
    actInBranch($this->other);
    $foreign = makeUser($this->other, 'cake_baker');
    actInBranch($this->branch);
    DB::table('user_station_assignments')->insert([
        'branch_id' => $this->other->id, 'user_id' => $foreign->id, 'station_id' => $this->cakeRoom->id,
        'can_count' => false, 'is_overseer' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $inactive->forceFill(['is_active' => false])->save();
    $gone->delete();

    $ids = $this->stations->assignedUsers($this->cakeRoom)->pluck('id')->all();

    expect($ids)->toBe([$this->people['cake_baker']->id, $second->id])
        ->and($this->stations->assignedUsers($this->bakery)->pluck('id')->all())->toBe([]);
})->group('contract:production->identity', 'contract');

it('decides isOverseer per station: the cake baker flagged at the bakery is not an overseer at the cake room', function () {
    $baker = $this->people['cake_baker'];
    $this->stations->replace($baker, [
        ['station_id' => $this->bakery->id, 'can_count' => true, 'is_overseer' => true],
        ['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false],
    ]);

    expect($this->stations->isOverseer($baker, $this->bakery))->toBeTrue()
        ->and($this->stations->isOverseer($baker, $this->cakeRoom))->toBeFalse()
        ->and($this->stations->isOverseer($this->people['bread_baker'], $this->bakery))->toBeFalse('not assigned there at all');

    // an inactive overseer overses nothing
    $baker->forceFill(['is_active' => false])->save();
    expect($this->stations->isOverseer($baker, $this->bakery))->toBeFalse();
})->group('contract:production->identity', 'contract');

it('answers onDuty from the interface: the assigned users under the Phase 2 binding, and the same shape under a roster-style binding', function () {
    $first = $this->people['cake_baker'];
    $second = makeUser($this->branch, 'cake_baker');
    $off = makeUser($this->branch, 'cake_baker');
    foreach ([$first, $second, $off] as $user) {
        ($this->assign)($user, $this->cakeRoom);
    }
    ($this->assign)($this->people['bread_baker'], $this->bakery);
    $off->forceFill(['is_active' => false])->save();

    // what must hold under any binding: the answer is a Collection of active users of this branch assigned to the
    // station, each once, in id order, and an unrelated station's people never appear
    $holdsFor = function (array $expected) use ($first, $second): void {
        $resolver = app(StationPresenceResolver::class);
        $onDuty = $resolver->onDuty($this->cakeRoom);
        expect($onDuty)->toBeInstanceOf(Collection::class)
            ->and($onDuty->pluck('id')->all())->toBe($expected)
            ->and($onDuty->every(fn ($u): bool => $u instanceof User && $u->is_active && $u->branch_id === $this->branch->id && $this->stations->isAssigned($u, $this->cakeRoom)))->toBeTrue()
            ->and($onDuty->pluck('id')->unique()->count())->toBe($onDuty->count())
            ->and($resolver->onDuty($this->bakery)->pluck('id')->all())->not->toContain($first->id, $second->id);
    };

    // the Phase 2 binding, named explicitly: from step 3.2b the container's default is the Shifts resolver, which this same
    // presence assertion passes against in tests/Contract/Shifts and tests/Modules/Shifts
    app()->bind(StationPresenceResolver::class, AssignedUsersResolver::class);
    expect(app(StationPresenceResolver::class))->toBeInstanceOf(AssignedUsersResolver::class);
    $holdsFor([$first->id, $second->id]);

    app()->instance(StationPresenceResolver::class, rosterResolver([$second->id, $off->id, $this->people['bread_baker']->id]));
    $holdsFor([$second->id]);

    app()->instance(StationPresenceResolver::class, rosterResolver([]));
    $holdsFor([]);
})->group('contract:production->identity', 'contract');

it('lets the cake baker fulfil at the cake room only: can(user, production.fulfil, station) by the section 7 matrix', function () {
    $this->stations->replace($this->people['cake_baker'], [['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false]]);
    $this->stations->replace($this->people['chef'], [['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false]]);
    $access = app(Access::class);
    $can = fn (string $role, Station $at): bool => $access->can($this->people[$role]->fresh() ?? $this->people[$role], 'production.fulfil', $at);

    expect($can('cake_baker', $this->cakeRoom))->toBeTrue()
        ->and($can('cake_baker', $this->bakery))->toBeFalse('the cake baker is not assigned to the bakery')
        ->and($can('chef', $this->cakeRoom))->toBeFalse('assigned, but a chef does not hold production.fulfil')
        ->and($can('admin', $this->cakeRoom))->toBeTrue('stations.all')
        ->and($can('admin', $this->bakery))->toBeTrue()
        ->and($can('manager', $this->cakeRoom))->toBeFalse('the manager confirms (production.confirm), the matrix does not give production.fulfil');
})->group('contract:production->identity', 'contract');

it('lets the admin and the manager confirm, and an overseer of the station co-sign there without holding production.confirm', function () {
    $this->stations->replace($this->people['cake_baker'], [['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => true]]);
    $this->stations->replace($this->people['bread_baker'], [['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false]]);
    $access = app(Access::class);
    // the rule Production applies when a station requires a second person: production.confirm, or overseer of that station
    $mayCosign = fn (string $role, Station $at): bool => $access->can($this->people[$role], 'production.confirm') || $this->stations->isOverseer($this->people[$role], $at);

    expect($access->can($this->people['admin'], 'production.confirm', $this->cakeRoom))->toBeTrue()
        ->and($access->can($this->people['manager'], 'production.confirm', $this->bakery))->toBeTrue('stations.all')
        ->and($access->can($this->people['cake_baker'], 'production.confirm'))->toBeFalse('the baker does not hold production.confirm')
        ->and($mayCosign('cake_baker', $this->cakeRoom))->toBeTrue('overseer at the cake room')
        ->and($mayCosign('cake_baker', $this->bakery))->toBeFalse('not an overseer at the bakery')
        ->and($mayCosign('bread_baker', $this->cakeRoom))->toBeFalse('assigned, not an overseer')
        ->and($mayCosign('manager', $this->cakeRoom))->toBeTrue()
        ->and($mayCosign('cashier', $this->cakeRoom))->toBeFalse();
})->group('contract:production->identity', 'contract');

it('attaches no shift under the Phase 2 binding: ShiftAttacher::attach answers null and writes nothing', function () {
    $this->stations->replace($this->people['cake_baker'], [['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false]]);
    $tables = ['audit_log', 'users', 'user_station_assignments', 'acting_sessions', 'devices', 'stock_movements'];
    $counts = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);
    $before = $counts();

    $attacher = app(ShiftAttacher::class);

    expect($attacher->attach($this->people['cake_baker'], $this->cakeRoom))->toBeNull()
        ->and($attacher->attach($this->people['admin'], $this->bakery))->toBeNull()
        ->and($counts())->toBe($before);
})->group('contract:production->identity', 'contract');

it('holds the Phase 2 registry permissions as plan section 10.5 says, and no one else holds them', function (string $permission, array $roles) {
    expect(PermissionRegistry::isPermission($permission))->toBeTrue("{$permission} is in the registry")
        ->and(PermissionRegistry::rolesFor($permission))->toEqualCanonicalizing($roles);

    $access = app(Access::class);
    foreach (PermissionRegistry::ROLES as $role) {
        expect($access->can($this->people[$role], $permission))->toBe(in_array($role, $roles, true), "{$role} / {$permission}");
    }
})->with([
    'read the production board and a card' => ['production.view', ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'plan: due time, priority, assign, cancel anyone\'s request' => ['production.plan', ['admin', 'manager']],
    'own notification inbox' => ['notifications.view', ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'costs and margins' => ['costs.view', ['admin', 'manager']],
])->group('contract:production->identity', 'contract');
