<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\Str;

/*
 * Every request a test makes starts in a fresh request scope, as it does in production (tests/TestCase.php): the
 * scoped bindings (CurrentBranch, ActorContext, ActingAsSession) and the controllers the router built for an earlier
 * request never carry into the next one, and the test's own context (actInBranch, withActor) is the same before and
 * after a request. Without it, a waiter acting by short code in one request would still be "acting" in the next one,
 * which production never does: a shared pad that sends no X-Acting-Session is the pad account itself.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->pad = loginAs(makeUser($this->branch, 'waiter', ['name' => 'Pad 1']));
    $this->device = Device::factory()->create(['branch_id' => $this->branch->id]);
    $this->tigist = makeUser($this->branch, 'waiter', ['name' => 'Tigist']);
});

it('forgets the person acting by short code once the request that named them is over', function () {
    $session = app(ActingAsSession::class)->start($this->tigist, $this->device);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('acting', null);
    $this->withHeaders(['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $this->device->fingerprint])
        ->getJson('/api/v1/me')->assertOk()->assertJsonPath('acting.user.id', $this->tigist->id);
    $this->flushHeaders();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('acting', null)->assertJsonPath('device', null);
})->group('feature', 'module:identity');

it('keeps the test\'s own branch and actor across a request, and gives the request its own', function () {
    withActor($this->tigist->id, null, null, null, null);
    $actor = app(ActorContext::class);
    $branch = app(CurrentBranch::class);

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/logout')->assertOk();

    expect(app(ActorContext::class))->toBe($actor)
        ->and(app(ActorContext::class)->userId())->toBe($this->tigist->id, 'the request authenticated the pad account in its own scope')
        ->and(app(CurrentBranch::class))->toBe($branch)
        ->and(app(CurrentBranch::class)->id())->toBe($this->branch->id);
})->group('feature', 'module:identity');
