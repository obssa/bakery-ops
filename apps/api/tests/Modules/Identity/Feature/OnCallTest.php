<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->group('module:identity', 'feature');

/*
 * Go-live settings (phase-5 plan, "Go-live decisions (2026-11-02)"): "the admin and the shift manager get the call, or anyone the admin
 * assigns to it". users.on_call (2026_11_12_100100, BOOLEAN NOT NULL DEFAULT false) is the per-person "On call" switch of Admin > People:
 * shown on every row of GET /users (and on /me, the same UserResource), set with PATCH /users/{id} like any other edit of a person (one
 * audited user.update with before and after), and only by the admin (people.manage). A PATCH that leaves it out keeps it. Who hears the
 * ops and print alerts because of it is Notifications' business (Recipients::onCall, NotifyOps, NotifyPrintFailed).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    loginAs($this->admin);
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->onCall = fn (int $id): bool => (bool) DB::table('users')->where('id', $id)->value('on_call');
});

it('adds users.on_call, off for everybody until the admin says otherwise', function () {
    expect(Schema::hasColumn('users', 'on_call'))->toBeTrue();
    $manager = makeUser($this->branch, 'manager');

    expect(($this->onCall)($manager->id))->toBeFalse()
        ->and(($this->onCall)($this->admin->id))->toBeFalse();
});

it('shows whether each person is on call in the people list, and on /me', function () {
    $manager = makeUser($this->branch, 'manager', ['name' => 'Almaz']);
    $cashier = makeUser($this->branch, 'cashier', ['name' => 'Bekele']);
    DB::table('users')->where('id', $cashier->id)->update(['on_call' => true]);

    $rows = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$manager->id]['on_call'])->toBeFalse()
        ->and($rows[$cashier->id]['on_call'])->toBeTrue()
        ->and($rows[$this->admin->id]['on_call'])->toBeFalse();
    loginAs($cashier);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('on_call', true);
});

it('puts a person on call and takes them off again, each an audited user.update with before and after', function () {
    $cashier = makeUser($this->branch, 'cashier', ['name' => 'Bekele']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$cashier->id}", ['on_call' => true])->assertOk()
        ->assertJsonPath('on_call', true)
        ->assertJsonPath('name', 'Bekele')
        ->assertJsonPath('roles', ['cashier']);
    expect(($this->onCall)($cashier->id))->toBeTrue();
    $row = AuditLog::query()->where('action', 'user.update')->where('subject_id', $cashier->id)->sole();
    expect($row->before)->toHaveKey('on_call')
        ->and((bool) $row->before['on_call'])->toBeFalse()
        ->and((bool) ($row->after['on_call'] ?? false))->toBeTrue()
        ->and($row->actor_id)->toBe($this->admin->id);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$cashier->id}", ['on_call' => false])->assertOk()->assertJsonPath('on_call', false);
    expect(($this->onCall)($cashier->id))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.update')->where('subject_id', $cashier->id)->count())->toBe(2);
});

it('keeps the switch when an edit leaves it out', function () {
    $manager = makeUser($this->branch, 'manager', ['name' => 'Before']);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$manager->id}", ['on_call' => true])->assertOk();

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$manager->id}", ['name' => 'After', 'locale' => 'en'])->assertOk()
        ->assertJsonPath('name', 'After')
        ->assertJsonPath('on_call', true);
    expect(($this->onCall)($manager->id))->toBeTrue();
});

it('validates the switch as a yes or no', function (mixed $value) {
    $cashier = makeUser($this->branch, 'cashier');

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$cashier->id}", ['on_call' => $value])->assertStatus(422)
        ->assertJsonValidationErrors(['on_call']);
    expect(($this->onCall)($cashier->id))->toBeFalse();
})->with(['maybe', 7, null]);

it('is set by the admin only (people.manage)', function () {
    $cashier = makeUser($this->branch, 'cashier');

    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        $someone = makeUser($this->branch, $role);
        loginAs($someone);
        $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$cashier->id}", ['on_call' => true])->assertStatus(403)
            ->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$someone->id}", ['on_call' => true])->assertStatus(403);
        expect(($this->onCall)($someone->id))->toBeFalse();
    }
    expect(($this->onCall)($cashier->id))->toBeFalse();
});
