<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Identity\Contracts\StationPresenceResolver;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Shared\Enums\StationCode;
use App\Shared\I18n\Bilingual;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Contract: notifications -> identity (manifest contracts["notifications->identity"]; phase-2 plan sections 2 and 5).
 *
 * Written BEFORE any code exists in App\Modules\Notifications (step 2.1): only Identity classes and factories. Phrased from
 * Notifications' side: "I never read users, roles or assignments myself. For an event I ask Identity who should hear
 * about it - the holders of some roles, the holders of a permission (optionally at one station), the people assigned to a
 * station, the people on duty there - and in which language to write to each of them. Every answer is people of one
 * branch, active, each once, in a stable order". It runs in Identity's CI job (mod-identity) for the life of the project.
 */

/** @return list<int> */
function recipientIds(Collection $users): array
{
    return $users->map(fn (User $u): int => $u->id)->values()->all();
}

beforeEach(function () {
    seedRoles();
    $this->other = Branch::factory()->create();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->people = [];
    foreach (PermissionRegistry::ROLES as $role) {
        $this->people[$role] = makeUser($this->branch, $role);
    }
    withActor($this->people['admin']->id);
    $this->stations = app(Stations::class);
    $this->assign = function (User $user, Station ...$stations): void {
        $this->stations->replace($user, array_map(fn (Station $s): array => ['station_id' => $s->id, 'can_count' => true, 'is_overseer' => false], $stations));
    };
    $this->recipients = app(Recipients::class);

    // a second branch with a person of every role, to prove nobody leaks across
    actInBranch($this->other);
    $this->foreign = [];
    foreach (PermissionRegistry::ROLES as $role) {
        $this->foreign[$role] = makeUser($this->other, $role);
    }
    $this->otherStation = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->other->id]);
    ($this->assign)($this->foreign['cake_baker'], $this->otherStation);
    actInBranch($this->branch);
});

it('finds recipients by role: active users of the branch only, each once even with two of the roles, in id order', function () {
    $second = makeUser($this->branch, 'manager');
    $both = makeUser($this->branch, 'manager');
    $both->assignRole('chef');
    $inactive = makeUser($this->branch, 'chef');
    $inactive->forceFill(['is_active' => false])->save();
    $gone = makeUser($this->branch, 'chef');
    $gone->delete();

    $ids = recipientIds($this->recipients->byRoles(['manager', 'chef']));

    expect($ids)->toBe([$this->people['manager']->id, $this->people['chef']->id, $second->id, $both->id])
        ->and($ids)->toBe(collect($ids)->unique()->sort()->values()->all(), 'stable order by id, no duplicate')
        ->and(recipientIds($this->recipients->byRoles(['chef'])))->toBe([$this->people['chef']->id, $both->id])
        ->and(recipientIds($this->recipients->byRoles([])))->toBe([])
        ->and(recipientIds($this->recipients->byRoles(['no_such_role'])))->toBe([]);
})->group('contract:notifications->identity', 'contract');

it('finds recipients by station assignment: the assigned active users, each once even when asked for several stations', function () {
    $baker = $this->people['cake_baker'];
    $second = makeUser($this->branch, 'cake_baker');
    $inactive = makeUser($this->branch, 'cake_baker');
    // the cake baker works in two stations
    ($this->assign)($baker, $this->cakeRoom, $this->bakery);
    ($this->assign)($second, $this->cakeRoom);
    ($this->assign)($inactive, $this->cakeRoom);
    ($this->assign)($this->people['chef'], $this->kitchen);
    $inactive->forceFill(['is_active' => false])->save();

    expect(recipientIds($this->recipients->forStation($this->cakeRoom)))->toBe([$baker->id, $second->id])
        ->and(recipientIds($this->recipients->forStation($this->bakery)))->toBe([$baker->id])
        ->and(recipientIds($this->recipients->forStation([$this->cakeRoom, $this->bakery])))->toBe([$baker->id, $second->id], 'the baker matches twice and is listed once')
        ->and(recipientIds($this->recipients->forStation([$this->bakery, $this->cakeRoom, $this->kitchen])))->toBe([$this->people['chef']->id, $baker->id, $second->id], 'in id order')
        ->and(recipientIds($this->recipients->forStation([])))->toBe([]);
})->group('contract:notifications->identity', 'contract');

it('names the language of a recipient, en or am with en as the fallback, and a Bilingual pair renders the matching side', function () {
    $amharic = makeUser($this->branch, 'cashier', ['locale' => 'am']);
    $english = makeUser($this->branch, 'cashier', ['locale' => 'en']);
    $pair = Bilingual::of('Black forest cake is sold out', 'ብላክ ፎረስት ኬክ አልቋል');

    expect($this->recipients->localeOf($amharic))->toBe('am')
        ->and($this->recipients->localeOf($english))->toBe('en')
        ->and($pair->in($this->recipients->localeOf($amharic)))->toBe('ብላክ ፎረስት ኬክ አልቋል')
        ->and($pair->in($this->recipients->localeOf($english)))->toBe('Black forest cake is sold out');

    // a locale the column would refuse is read as English rather than failing the notification
    $odd = $english->replicate();
    $odd->locale = 'fr';
    $none = $english->replicate();
    $none->locale = '';
    expect($this->recipients->localeOf($odd))->toBe('en')
        ->and($this->recipients->localeOf($none))->toBe('en');
})->group('contract:notifications->identity', 'contract');

it('finds recipients by permission, honouring what a user was granted by hand and the roles a user holds', function () {
    // production.confirm is held by the admin and the manager; the cashier is granted it by hand
    $granted = makeUser($this->branch, 'cashier');
    $granted->givePermissionTo('production.confirm');
    // a manager whose role was taken away no longer has it (nothing is left to inherit)
    $demoted = makeUser($this->branch, 'manager');
    $demoted->removeRole('manager');
    $demoted->assignRole('waiter');
    $inactive = makeUser($this->branch, 'manager');
    $inactive->forceFill(['is_active' => false])->save();

    $ids = recipientIds($this->recipients->byPermission('production.confirm'));

    expect($ids)->toBe([$this->people['admin']->id, $this->people['manager']->id, $granted->id])
        ->and($ids)->not->toContain($this->people['cashier']->id, $demoted->id, $inactive->id)
        ->and(recipientIds($this->recipients->byPermission('no.such.permission')))->toBe([]);
})->group('contract:notifications->identity', 'contract');

it('narrows a permission to a station: the people assigned there plus those who hold stations.all', function () {
    // production.fulfil is held by the admin and the two bakers
    $assigned = $this->people['cake_baker'];
    $elsewhere = $this->people['bread_baker'];
    ($this->assign)($assigned, $this->cakeRoom);
    ($this->assign)($elsewhere, $this->bakery);
    // production.confirm: admin and manager hold stations.all; a cashier granted it by hand is assigned to the bakery only
    $cashier = makeUser($this->branch, 'cashier');
    $cashier->givePermissionTo('production.confirm');
    ($this->assign)($cashier, $this->bakery);

    expect(recipientIds($this->recipients->byPermission('production.fulfil', $this->cakeRoom)))->toBe([$this->people['admin']->id, $assigned->id], 'admin everywhere, the baker assigned here, not the baker of the bakery')
        ->and(recipientIds($this->recipients->byPermission('production.fulfil', $this->bakery)))->toBe([$this->people['admin']->id, $elsewhere->id])
        ->and(recipientIds($this->recipients->byPermission('production.confirm', $this->bakery)))->toBe([$this->people['admin']->id, $this->people['manager']->id, $cashier->id])
        ->and(recipientIds($this->recipients->byPermission('production.confirm', $this->cakeRoom)))->toBe([$this->people['admin']->id, $this->people['manager']->id], 'the cashier is assigned to the bakery, not here');
})->group('contract:notifications->identity', 'contract');

it('answers onDuty(station) from the presence resolver and falls back to the assigned users when nobody is on duty', function () {
    $first = $this->people['cake_baker'];
    $second = makeUser($this->branch, 'cake_baker');
    ($this->assign)($first, $this->cakeRoom);
    ($this->assign)($second, $this->cakeRoom);

    // the Phase 2 binding: on duty means assigned, so the two answers are the same set
    expect(recipientIds($this->recipients->onDuty($this->cakeRoom)))->toBe([$first->id, $second->id])
        ->and(recipientIds($this->recipients->onDuty($this->cakeRoom)))->toBe(recipientIds($this->recipients->forStation($this->cakeRoom)));

    // a roster-style binding (Phase 3): only the second baker is on the roster
    $roster = fn (array $ids): StationPresenceResolver => new class($ids) implements StationPresenceResolver
    {
        /** @param list<int> $ids */
        public function __construct(private readonly array $ids) {}

        public function onDuty(Station $station): Collection
        {
            return User::query()->whereKey($this->ids)->orderBy('id')->get();
        }
    };
    app()->instance(StationPresenceResolver::class, $roster([$second->id]));
    expect(recipientIds($this->recipients->onDuty($this->cakeRoom)))->toBe([$second->id]);

    // nobody on the roster: the people assigned to the station are told instead
    app()->instance(StationPresenceResolver::class, $roster([]));
    expect(recipientIds($this->recipients->onDuty($this->cakeRoom)))->toBe([$first->id, $second->id]);
})->group('contract:notifications->identity', 'contract');

it('never returns a user of another branch, whatever the question', function () {
    // the foreign branch has a person of every role, one of them at a station of their own
    $foreignIds = collect($this->foreign)->map(fn (User $u): int => $u->id)->values()->all();
    $foreignAdmin = $this->foreign['admin'];
    $foreignAdmin->givePermissionTo('production.confirm');
    // and a foreign user assigned (by a data error) to this branch's station
    actInBranch($this->other);
    DB::table('user_station_assignments')->insert([
        'branch_id' => $this->other->id, 'user_id' => $this->foreign['chef']->id, 'station_id' => $this->cakeRoom->id,
        'can_count' => false, 'is_overseer' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    actInBranch($this->branch);

    $answers = [
        $this->recipients->byRoles(PermissionRegistry::ROLES),
        $this->recipients->byPermission('production.confirm'),
        $this->recipients->byPermission('production.confirm', $this->cakeRoom),
        $this->recipients->forStation([$this->cakeRoom, $this->bakery, $this->kitchen]),
        $this->recipients->onDuty($this->cakeRoom),
    ];

    foreach ($answers as $answer) {
        expect(array_intersect(recipientIds($answer), $foreignIds))->toBe([])
            ->and($answer->every(fn (User $u): bool => $u->branch_id === $this->branch->id))->toBeTrue();
    }
    expect(recipientIds($this->recipients->byRoles(PermissionRegistry::ROLES)))->toBe(array_map(fn (User $u): int => $u->id, array_values($this->people)));
})->group('contract:notifications->identity', 'contract');
