<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/*
 * Contract: orders -> identity (manifest contracts["orders->identity"]).
 *
 * Written BEFORE any code exists in App\Modules\Orders (step 1.1): only Identity classes (ShortCodes, ActingAsSession,
 * Access, Stations, PermissionRegistry, the `actor` middleware and spatie's `permission:` middleware wired by Identity)
 * and factories. Phrased from Orders' side: "on the shared pad a waiter acts by short code for 10 minutes and every
 * order write carries that person; the kitchen and the bar act only at their own station; a void needs the PIN of
 * someone allowed to void". Orders has no routes yet, so the acting-session case mounts a probe route with exactly
 * the middleware every Orders write will carry and reports what Identity resolved. It runs in Identity's CI job
 * (mod-identity) for the life of the project.
 */

/** @return array<string, int|null> what the request context resolved, which every Orders write will stamp */
function ordersProbeContext(): array
{
    $actor = app(ActorContext::class);

    return [
        'branch_id' => app(CurrentBranch::class)->idOrNull(),
        'user_id' => $actor->userId(),
        'acting_user_id' => $actor->actingUserId(),
        'device_id' => $actor->deviceId(),
    ];
}

beforeEach(function () {
    seedRoles();
    Route::post('api/v1/contract-probe/orders/write', fn () => response()->json(ordersProbeContext()))
        ->middleware(['api', 'auth:sanctum', 'actor', 'permission:orders.create', 'idempotency']);

    $this->other = Branch::factory()->create();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b]);
    $this->people = [];
    foreach (PermissionRegistry::ROLES as $role) {
        $this->people[$role] = makeUser($this->branch, $role);
    }
    withActor($this->people['admin']->id);
    $this->codes = app(ShortCodes::class);
});

it('rejects with verifyShortCode a code from another branch, and finds the right person when two branches share a code', function () {
    $waiter = $this->people['waiter'];
    $this->codes->set($waiter, '4821');
    actInBranch($this->other);
    $elsewhere = makeUser($this->other, 'waiter');
    $twin = makeUser($this->other, 'waiter');
    $this->codes->set($elsewhere, '7391');
    $this->codes->set($twin, '4821');
    actInBranch($this->branch);

    try {
        $this->codes->verify($this->branch->id, '7391', 'front-pad');
        $this->fail('a code of another branch must not open a session here');
    } catch (IdentityError $e) {
        expect($e->messageKey)->toBe('identity.short_code.invalid')->and($e->status)->toBe(401);
    }

    expect($this->codes->verify($this->branch->id, '4821', 'front-pad')->id)->toBe($waiter->id)
        ->and($this->codes->verify($this->other->id, '4821', 'other-pad')->id)->toBe($twin->id)
        ->and($this->codes->verify($this->other->id, '7391', 'other-pad')->id)->toBe($elsewhere->id);
})->group('contract:orders->identity', 'contract');

it('ends the acting-as session at 10 minutes: an order write at 9:59 carries the waiter, at 10:00 it is refused', function () {
    $padAccount = makeUser($this->branch, 'waiter', ['name' => 'Pad 1']);
    $waiter = $this->people['waiter'];
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Front pad']);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 07:00:00', 'UTC'));
    $session = app(ActingAsSession::class)->start($waiter, $device);

    expect($session->expires_at->equalTo($session->started_at->addMinutes(10)))->toBeTrue()
        ->and(app(ActingAsSession::class)->minutes())->toBe(10);

    loginAs($padAccount);
    $write = fn () => $this->withHeaders(idempotencyKey() + [
        'X-Acting-Session' => (string) $session->id,
        'X-Device-Id' => $device->fingerprint,
    ])->postJson('/api/v1/contract-probe/orders/write', []);

    $this->travelTo($session->started_at->addMinutes(9)->addSeconds(59));
    $write()->assertOk()
        ->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('user_id', $padAccount->id)
        ->assertJsonPath('acting_user_id', $waiter->id)
        ->assertJsonPath('device_id', $device->id);

    $this->travelTo($session->started_at->addMinutes(10));
    $write()->assertStatus(401)->assertJsonPath('message_key', 'identity.acting_session.expired');
    expect(app(ActingAsSession::class)->find($session->id))->toBeNull();
})->group('contract:orders->identity', 'contract');

it('accepts with verifyPinFor(\'orders.void\') a manager or the admin and names the approver; rejects a cashier and another branch', function () {
    $this->codes->set($this->people['manager'], '2468');
    $this->codes->set($this->people['admin'], '8642');
    $this->codes->set($this->people['cashier'], '1357');
    actInBranch($this->other);
    $foreignManager = makeUser($this->other, 'manager');
    $this->codes->set($foreignManager, '9753');
    actInBranch($this->branch);
    $access = app(Access::class);

    // the cashier asks for the void at the till; the manager types the PIN
    withActor($this->people['cashier']->id);
    expect($access->verifyPinFor('orders.void', '2468')->id)->toBe($this->people['manager']->id)
        ->and($access->verifyPinFor('orders.void', '8642')->id)->toBe($this->people['admin']->id);

    $rejections = 0;
    foreach (['1357' => 'the cashier holds orders.void_request, not orders.void', '9753' => 'a manager of another branch', '0000' => 'nobody', '1111' => 'nobody', '2222' => 'nobody'] as $pin => $why) {
        try {
            $access->verifyPinFor('orders.void', (string) $pin);
            $this->fail("PIN {$pin} ({$why}) must be rejected");
        } catch (IdentityError $e) {
            expect($e->messageKey)->toBe('identity.pin.rejected', $why)->and($e->status)->toBe(403);
            $rejections++;
        }
    }

    // five wrong PINs lock the prompt, even for the right one
    expect($rejections)->toBe(5);
    try {
        $access->verifyPinFor('orders.void', '2468');
        $this->fail('the PIN prompt is locked after five wrong PINs');
    } catch (IdentityError $e) {
        expect($e->messageKey)->toBe('identity.short_code.locked')->and($e->status)->toBe(423);
    }
})->group('contract:orders->identity', 'contract');

it('answers can(user, \'orders.accept\', station) for the station the line is at', function () {
    $stations = app(Stations::class);
    $stations->replace($this->people['chef'], [['station_id' => $this->kitchen->id, 'can_count' => true, 'is_overseer' => false]]);
    $stations->replace($this->people['barista'], [['station_id' => $this->bar->id, 'can_count' => true, 'is_overseer' => false]]);
    $stations->replace($this->people['waiter'], [['station_id' => $this->kitchen->id, 'can_count' => false, 'is_overseer' => false]]);
    $access = app(Access::class);
    $can = fn (string $role, Station $at): bool => $access->can($this->people[$role]->fresh() ?? $this->people[$role], 'orders.accept', $at);

    expect($can('chef', $this->kitchen))->toBeTrue()
        ->and($can('chef', $this->bar))->toBeFalse('the chef is not assigned to the bar')
        ->and($can('barista', $this->bar))->toBeTrue()
        ->and($can('barista', $this->kitchen))->toBeFalse()
        ->and($can('manager', $this->bar))->toBeTrue('stations.all')
        ->and($can('admin', $this->kitchen))->toBeTrue()
        ->and($can('waiter', $this->kitchen))->toBeFalse('assigned, but a waiter does not accept lines')
        ->and($can('cashier', $this->kitchen))->toBeFalse();

    // an inactive chef accepts nothing anywhere
    $this->people['chef']->forceFill(['is_active' => false])->save();
    expect($can('chef', $this->kitchen))->toBeFalse();
})->group('contract:orders->identity', 'contract');

it('holds the order permissions as the section 7 matrix says, through the seeded roles', function (string $permission, array $roles) {
    expect(PermissionRegistry::isPermission($permission))->toBeTrue("{$permission} is in the registry")
        ->and(PermissionRegistry::rolesFor($permission))->toEqualCanonicalizing($roles);

    $access = app(Access::class);
    foreach (PermissionRegistry::ROLES as $role) {
        /** @var User $user */
        $user = $this->people[$role];
        expect($access->can($user, $permission))->toBe(in_array($role, $roles, true), "{$role} / {$permission}");
    }
})->with([
    'create an order, mark served' => ['orders.create', ['admin', 'manager', 'cashier', 'waiter']],
    'accept / ready / reject a line' => ['orders.accept', ['admin', 'manager', 'chef', 'barista']],
    'approve a void (PIN)' => ['orders.void', ['admin', 'manager']],
    'ask for a void' => ['orders.void_request', ['admin', 'manager', 'cashier']],
    'manage the dining tables' => ['tables.manage', ['admin']],
])->group('contract:orders->identity', 'contract');
