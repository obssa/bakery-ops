<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Contracts\People;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Resolvers\AssignedUsersResolver;
use App\Modules\Identity\Resolvers\NullShiftAttacher;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeOpenShifts;

/*
 * Contract: shifts -> identity (manifest contracts["shifts->identity"]; phase-3 plan sections 2 and 10.6;
 * dependency-graph.md, cycle 3).
 *
 * Written BEFORE any code exists in App\Modules\Shifts (step 3.1a): only Identity and Shared classes and factories.
 * Phrased from Shifts' side: "I decide who may count, approve, co-sign and unlock per station; I am asked who is on
 * duty through an interface I am rebound under, and I may attach a shift to a person's work; every request arrives
 * with the station the person works at and the shift running there already in ActorContext, which I never fill myself;
 * the client learns its stations and their running shift from GET /me". It runs in Identity's CI job (mod-identity)
 * for the life of the project.
 *
 * Cases 7 to 12 are the provider changes of step 3.1a (new registry permissions, Stations::workStationFor, ResolveActor
 * stamping station and shift through Shared's OpenShifts, People::attachShift stamping ActorContext, GET /me
 * work_stations) and were red before them; the others pin behaviour that already holds.
 */

/** A roster-style presence resolver, the shape Shifts binds: only those who joined the running shift are on duty. */
function shiftsRosterResolver(array $userIds): StationPresenceResolver
{
    return new class($userIds) implements StationPresenceResolver
    {
        /** @param list<int> $userIds joined and not left, at the running shift only */
        public function __construct(private readonly array $userIds) {}

        public function onDuty(Station $station): Collection
        {
            return User::query()->whereKey($this->userIds)->orderBy('id')->get();
        }
    };
}

beforeEach(function () {
    seedRoles();
    $this->other = Branch::factory()->create();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b]);
    $this->till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $this->people = [];
    foreach (PermissionRegistry::ROLES as $role) {
        $this->people[$role] = makeUser($this->branch, $role);
    }
    withActor($this->people['admin']->id);
    $this->stations = app(Stations::class);
    $this->assign = fn (User $user, Station $station, bool $overseer = false, bool $count = true) => $this->stations->replace($user, [['station_id' => $station->id, 'can_count' => $count, 'is_overseer' => $overseer]]);
    $this->can = fn (string $role, string $permission, ?Station $at = null): bool => app(Access::class)->can($this->people[$role]->fresh() ?? $this->people[$role], $permission, $at);
});

it('decides canCount and isOverseer per station, and an inactive or soft-deleted user is neither', function () {
    $baker = $this->people['cake_baker'];
    $this->stations->replace($baker, [
        ['station_id' => $this->bakery->id, 'can_count' => true, 'is_overseer' => true],
        ['station_id' => $this->cakeRoom->id, 'can_count' => true, 'is_overseer' => false],
    ]);
    $this->stations->replace($this->people['bread_baker'], [['station_id' => $this->bakery->id, 'can_count' => false, 'is_overseer' => false]]);

    expect($this->stations->isOverseer($baker, $this->bakery))->toBeTrue()
        ->and($this->stations->isOverseer($baker, $this->cakeRoom))->toBeFalse('flagged at the bakery only')
        ->and($this->stations->canCount($baker, $this->cakeRoom))->toBeTrue()
        ->and($this->stations->canCount($baker, $this->kitchen))->toBeFalse('not assigned there')
        ->and($this->stations->canCount($this->people['bread_baker'], $this->bakery))->toBeFalse('assigned without can_count');

    $baker->forceFill(['is_active' => false])->save();
    expect($this->stations->isOverseer($baker, $this->bakery))->toBeFalse()
        ->and($this->stations->canCount($baker, $this->cakeRoom))->toBeFalse();
    $baker->forceFill(['is_active' => true])->save();
    $baker->delete();
    expect($this->stations->isOverseer($baker, $this->bakery))->toBeFalse()
        ->and($this->stations->canCount($baker, $this->cakeRoom))->toBeFalse();
})->group('contract:shifts->identity', 'contract');

it('rebinds StationPresenceResolver to a roster-style resolver and Recipients::onDuty keeps active users of the branch, once each, in id order', function () {
    $first = $this->people['cake_baker'];
    $second = makeUser($this->branch, 'cake_baker');
    $inactive = makeUser($this->branch, 'cake_baker');
    foreach ([$first, $second, $inactive] as $user) {
        ($this->assign)($user, $this->cakeRoom);
    }
    $inactive->forceFill(['is_active' => false])->save();

    // the roster names them out of order and one twice: what reaches a caller is still unique, active and in id order
    app()->instance(StationPresenceResolver::class, shiftsRosterResolver([$second->id, $inactive->id, $first->id, $second->id]));

    expect(app(StationPresenceResolver::class)->onDuty($this->cakeRoom))->toBeInstanceOf(Collection::class)
        ->and(app(Recipients::class)->onDuty($this->cakeRoom)->pluck('id')->all())->toBe([$first->id, $second->id]);
})->group('contract:shifts->identity', 'contract');

it('passes the presence assertions of the production and notifications contracts under a roster binding, including the fallback to the assigned people when the roster is empty', function () {
    $first = $this->people['cake_baker'];
    $second = makeUser($this->branch, 'cake_baker');
    $off = makeUser($this->branch, 'cake_baker');
    foreach ([$first, $second, $off] as $user) {
        ($this->assign)($user, $this->cakeRoom);
    }
    ($this->assign)($this->people['bread_baker'], $this->bakery);
    $off->forceFill(['is_active' => false])->save();

    // production->identity: whatever the binding, the answer is a Collection of active users of this branch assigned to
    // the station, each once, in id order, and an unrelated station's people never appear
    $holdsFor = function (array $expected) use ($first, $second): void {
        $resolver = app(StationPresenceResolver::class);
        $onDuty = $resolver->onDuty($this->cakeRoom);
        expect($onDuty)->toBeInstanceOf(Collection::class)
            ->and($onDuty->pluck('id')->all())->toBe($expected)
            ->and($onDuty->every(fn ($u): bool => $u instanceof User && $u->is_active && $u->branch_id === $this->branch->id && $this->stations->isAssigned($u, $this->cakeRoom)))->toBeTrue()
            ->and($resolver->onDuty($this->bakery)->pluck('id')->all())->not->toContain($first->id, $second->id);
    };
    // notifications->identity: onDuty(station) is the resolver's answer, and the assigned people when nobody is on duty
    $recipientIds = fn (): array => app(Recipients::class)->onDuty($this->cakeRoom)->pluck('id')->all();

    expect(app(StationPresenceResolver::class))->toBeInstanceOf(AssignedUsersResolver::class, 'the Phase 2 default, until Shifts rebinds it');
    $holdsFor([$first->id, $second->id]);

    app()->instance(StationPresenceResolver::class, shiftsRosterResolver([$second->id]));
    $holdsFor([$second->id]);
    expect($recipientIds())->toBe([$second->id]);

    // nobody has joined the running shift (or none is open): the roster is empty and the assigned people are asked
    app()->instance(StationPresenceResolver::class, shiftsRosterResolver([]));
    $holdsFor([]);
    expect($recipientIds())->toBe([$first->id, $second->id]);
})->group('contract:shifts->identity', 'contract');

it('decides shifts.count by role and station: the chef at the kitchen not at the bar, the cashier at the till, the waiter nowhere, the admin and manager anywhere', function () {
    ($this->assign)($this->people['chef'], $this->kitchen);
    ($this->assign)($this->people['cashier'], $this->till);
    ($this->assign)($this->people['waiter'], $this->till);

    expect(($this->can)('chef', 'shifts.count', $this->kitchen))->toBeTrue()
        ->and(($this->can)('chef', 'shifts.count', $this->bar))->toBeFalse('not assigned to the bar')
        ->and(($this->can)('cashier', 'shifts.count', $this->till))->toBeTrue()
        ->and(($this->can)('waiter', 'shifts.count', $this->till))->toBeFalse('the waiter holds no shifts.count, even assigned')
        ->and(($this->can)('waiter', 'shifts.count', $this->kitchen))->toBeFalse()
        ->and(($this->can)('admin', 'shifts.count', $this->bar))->toBeTrue('stations.all')
        ->and(($this->can)('manager', 'shifts.count', $this->cakeRoom))->toBeTrue('stations.all');
})->group('contract:shifts->identity', 'contract');

it('holds shifts.approve for the admin and manager only and shifts.unlock for the admin only', function () {
    foreach (PermissionRegistry::ROLES as $role) {
        expect(($this->can)($role, 'shifts.approve'))->toBe(in_array($role, ['admin', 'manager'], true), "{$role} / shifts.approve")
            ->and(($this->can)($role, 'shifts.unlock'))->toBe($role === 'admin', "{$role} / shifts.unlock");
    }
})->group('contract:shifts->identity', 'contract');

it('lets an overseer of the station co-sign there without holding shifts.cosign, a holder anywhere, and nobody else', function () {
    $this->stations->replace($this->people['chef'], [['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => true]]);
    $this->stations->replace($this->people['barista'], [['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false]]);
    // the rule Shifts applies when a count needs a second person: shifts.cosign, or overseer of that station
    $mayCosign = fn (string $role, Station $at): bool => ($this->can)($role, 'shifts.cosign') || $this->stations->isOverseer($this->people[$role], $at);

    expect(($this->can)('chef', 'shifts.cosign'))->toBeFalse('the chef holds no shifts.cosign')
        ->and($mayCosign('chef', $this->kitchen))->toBeTrue('overseer at the kitchen')
        ->and($mayCosign('chef', $this->bar))->toBeFalse('not an overseer at the bar')
        ->and($mayCosign('barista', $this->kitchen))->toBeFalse('assigned, not an overseer')
        ->and($mayCosign('admin', $this->bar))->toBeTrue()
        ->and($mayCosign('manager', $this->cakeRoom))->toBeTrue()
        ->and($mayCosign('cashier', $this->till))->toBeFalse()
        ->and($mayCosign('waiter', $this->till))->toBeFalse();
})->group('contract:shifts->identity', 'contract');

it('holds the Phase 3 registry permissions as plan section 10.6 says, and no one else holds them', function (string $permission, array $roles) {
    expect(PermissionRegistry::isPermission($permission))->toBeTrue("{$permission} is in the registry")
        ->and(PermissionRegistry::rolesFor($permission))->toEqualCanonicalizing($roles);

    foreach (PermissionRegistry::ROLES as $role) {
        expect(($this->can)($role, $permission))->toBe(in_array($role, $roles, true), "{$role} / {$permission}");
    }
})->with([
    'read shifts, counts, templates, notes and the handover at one\'s stations' => ['shifts.view', ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'templates, reasons, scheduling, roster, cancelling, force start' => ['shifts.manage', ['admin']],
    'the replay, the chain and its verification' => ['shifts.replay', ['admin', 'manager']],
])->group('contract:shifts->identity', 'contract');

it('answers workStationFor: the station X-Station-Id names when the user works there, else their only assignment, else null', function () {
    ($this->assign)($this->people['chef'], $this->kitchen);
    $this->stations->replace($this->people['cashier'], [
        ['station_id' => $this->till->id, 'can_count' => true, 'is_overseer' => false],
        ['station_id' => $this->bar->id, 'can_count' => true, 'is_overseer' => false],
    ]);
    actInBranch($this->other);
    $foreign = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->other->id]);
    actInBranch($this->branch);

    $chef = $this->people['chef'];
    $cashier = $this->people['cashier'];
    $admin = $this->people['admin'];

    expect($this->stations->workStationFor($chef, null)?->id)->toBe($this->kitchen->id, 'the only assignment')
        ->and($this->stations->workStationFor($chef, $this->kitchen->id)?->id)->toBe($this->kitchen->id)
        ->and($this->stations->workStationFor($chef, $this->bar->id)?->id)->toBe($this->kitchen->id, 'a header naming a station the chef may not work at is ignored')
        ->and($this->stations->workStationFor($cashier, $this->bar->id)?->id)->toBe($this->bar->id)
        ->and($this->stations->workStationFor($cashier, null))->toBeNull('two assignments and no header is never a guess')
        ->and($this->stations->workStationFor($cashier, $this->kitchen->id))->toBeNull('two assignments, and the header names a third')
        ->and($this->stations->workStationFor($admin, $this->cakeRoom->id)?->id)->toBe($this->cakeRoom->id, 'stations.all works anywhere')
        ->and($this->stations->workStationFor($admin, null))->toBeNull('no assignment, no header')
        ->and($this->stations->workStationFor($admin, $foreign->id))->toBeNull('another branch\'s station is ignored')
        ->and($this->stations->workStationFor($this->people['waiter'], $this->till->id))->toBeNull();
})->group('contract:shifts->identity', 'contract');

it('stamps ActorContext with the resolved station and the running shift of OpenShifts, and no shift when none runs', function () {
    ($this->assign)($this->people['chef'], $this->kitchen);
    $double = new FakeOpenShifts([$this->kitchen->id => 9001]);
    app()->instance(App\Shared\Shifts\OpenShifts::class, $double);

    $run = function (User $user, array $headers = []): ActorContext {
        app(ActorContext::class)->reset();
        $request = Request::create('/api/v1/anything', 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, (string) $value);
        }
        $request->setUserResolver(fn (): User => $user);
        app(ResolveActor::class)->handle($request, fn () => response('ok'));

        return app(ActorContext::class);
    };

    $chef = $run($this->people['chef']);
    expect($chef->stationId())->toBe($this->kitchen->id, 'the only assignment')
        ->and($chef->shiftInstanceId())->toBe(9001);

    $cashier = $this->people['cashier'];
    ($this->assign)($cashier, $this->till);
    $atTill = $run($cashier, ['X-Station-Id' => $this->till->id]);
    expect($atTill->stationId())->toBe($this->till->id)
        ->and($atTill->shiftInstanceId())->toBeNull('nothing runs at the till');

    $nowhere = $run($this->people['waiter']);
    expect($nowhere->stationId())->toBeNull()->and($nowhere->shiftInstanceId())->toBeNull();
})->group('contract:shifts->identity', 'contract');

it('does not consult OpenShifts for an unauthenticated request', function () {
    $double = new FakeOpenShifts([$this->kitchen->id => 9001]);
    app()->instance(App\Shared\Shifts\OpenShifts::class, $double);
    app(ActorContext::class)->reset();
    $request = Request::create('/api/v1/anything', 'GET', server: ['HTTP_X_STATION_ID' => (string) $this->kitchen->id]);
    $request->setUserResolver(fn () => null);

    app(ResolveActor::class)->handle($request, fn () => response('ok'));

    expect($double->asked)->toBe([])
        ->and(app(ActorContext::class)->isAuthenticated())->toBeFalse()
        ->and(app(ActorContext::class)->shiftInstanceId())->toBeNull();
})->group('contract:shifts->identity', 'contract');

it('puts the shift the attacher answered into ActorContext, and leaves it alone when the attacher answers null', function () {
    ($this->assign)($this->people['cake_baker'], $this->cakeRoom);
    $attacher = fn (?int $answer): ShiftAttacher => new class($answer) implements ShiftAttacher
    {
        public function __construct(private readonly ?int $answer) {}

        public function attach(User $user, Station $station): ?int
        {
            return $this->answer;
        }
    };

    app()->instance(ShiftAttacher::class, $attacher(5150));
    withActor($this->people['cake_baker']->id);
    $answered = app(People::class)->attachShift($this->people['cake_baker']->id, $this->cakeRoom);
    expect($answered)->toBe(5150)->and(app(ActorContext::class)->shiftInstanceId())->toBe(5150, 'the rest of the request is stamped');

    app()->instance(ShiftAttacher::class, $attacher(null));
    withActor($this->people['cake_baker']->id, null, null, null, 77);
    expect(app(People::class)->attachShift($this->people['cake_baker']->id, $this->cakeRoom))->toBeNull()
        ->and(app(ActorContext::class)->shiftInstanceId())->toBe(77, 'untouched');
})->group('contract:shifts->identity', 'contract');

it('carries work_stations on GET /me: the assigned stations, or every station for stations.all, with tracks_shifts, requires_overseer and the running shift', function () {
    $this->kitchen->forceFill(['requires_overseer' => true])->save();
    ($this->assign)($this->people['chef'], $this->kitchen);
    app()->instance(App\Shared\Shifts\OpenShifts::class, new FakeOpenShifts([$this->kitchen->id => 9001]));

    loginAs($this->people['chef']);
    $mine = $this->getJson('/api/v1/me')->assertOk()->json('work_stations');
    expect($mine)->toHaveCount(1)
        ->and($mine[0])->toMatchArray([
            'station_id' => $this->kitchen->id, 'code' => 'kitchen', 'name_en' => $this->kitchen->name_en, 'name_am' => $this->kitchen->name_am,
            'tracks_shifts' => true, 'requires_overseer' => true,
        ])
        ->and($mine[0]['current_shift'])->toMatchArray(['id' => 9001, 'status' => 'open'])
        ->and(array_keys($mine[0]['current_shift']))->toBe(['id', 'status', 'date', 'opened_at']);

    loginAs($this->people['admin']);
    $all = $this->getJson('/api/v1/me')->assertOk()->json('work_stations');
    expect(collect($all)->pluck('station_id')->sort()->values()->all())->toBe(collect([$this->kitchen, $this->bar, $this->till, $this->cakeRoom, $this->bakery])->pluck('id')->sort()->values()->all())
        ->and(collect($all)->firstWhere('station_id', $this->bar->id)['current_shift'])->toBeNull();

    loginAs($this->people['waiter']);
    expect($this->getJson('/api/v1/me')->assertOk()->json('work_stations'))->toBe([]);
})->group('contract:shifts->identity', 'contract');

it('pins ShiftAttacher under its Phase 2 binding: NullShiftAttacher answers null and writes nothing', function () {
    ($this->assign)($this->people['cake_baker'], $this->cakeRoom);
    // bound explicitly: from step 3.2b the container's default is the Shifts attacher
    app()->instance(ShiftAttacher::class, new NullShiftAttacher);
    $tables = ['audit_log', 'users', 'user_station_assignments', 'acting_sessions', 'devices', 'stock_movements'];
    $counts = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);
    $before = $counts();

    expect(app(ShiftAttacher::class)->attach($this->people['cake_baker'], $this->cakeRoom))->toBeNull()
        ->and(app(ShiftAttacher::class)->attach($this->people['admin'], $this->bakery))->toBeNull()
        ->and($counts())->toBe($before);
})->group('contract:shifts->identity', 'contract');

it('never returns a user of another branch or an inactive user from any presence answer, whatever the resolver says', function () {
    $ok = $this->people['cake_baker'];
    ($this->assign)($ok, $this->cakeRoom);
    $inactive = makeUser($this->branch, 'cake_baker');
    ($this->assign)($inactive, $this->cakeRoom);
    $inactive->forceFill(['is_active' => false])->save();
    actInBranch($this->other);
    $foreign = makeUser($this->other, 'cake_baker');
    actInBranch($this->branch);

    // a careless resolver names everyone: Recipients is the filter every caller goes through
    $everyone = new class([$ok->id, $inactive->id, $foreign->id]) implements StationPresenceResolver
    {
        public function __construct(private readonly array $ids) {}

        public function onDuty(Station $station): Collection
        {
            return User::query()->withoutGlobalScopes()->whereKey($this->ids)->orderBy('id')->get();
        }
    };
    app()->instance(StationPresenceResolver::class, $everyone);

    expect(app(Recipients::class)->onDuty($this->cakeRoom)->pluck('id')->all())->toBe([$ok->id])
        ->and(app(Recipients::class)->forStation($this->cakeRoom)->pluck('id')->all())->toBe([$ok->id])
        ->and($this->stations->assignedUsers($this->cakeRoom)->pluck('id')->all())->toBe([$ok->id]);
})->group('contract:shifts->identity', 'contract');
