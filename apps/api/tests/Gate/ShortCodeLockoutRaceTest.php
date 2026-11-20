<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\Device;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * The short-code lockout under real concurrency (manifest identity.provides: "5 attempts then 15-min lockout"). Each try runs in
 * its own process on its own connection through the whole HTTP stack, all at the same moment. A burst of wrong tries must never
 * check more than 5 codes before the lock, whichever counter is hit:
 *   - the named person (users.short_code_attempts / pin_locked_until): ten pads name the same waiter at once; the row is locked
 *     while the code is checked, so exactly 5 tries are checked (401) and the other 5 are refused as locked (423);
 *   - the entry point (AttemptCounter, the cache): ten wrong tries from one pad at once; each takes its attempt by an atomic
 *     increment before its code is checked, so the same 5 / 5 split holds. The cache is the database store here, as on the live
 *     host: the array store of the other tests lives in one process and would not be shared between the children.
 * The read-count, check, write-count code this replaces let all ten read the same count, so all ten were checked (ten 401s).
 */

uses(CommitsForConcurrency::class);

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-1']);
    $this->waiter = makeUser($this->branch, 'waiter');
    actInBranch($this->branch);
    withActor(makeUser($this->branch, 'admin')->id);
    app(ShortCodes::class)->set($this->waiter, '4821');
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();

    config(['cache.default' => 'database']);
    app('cache')->setDefaultDriver('database');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(ShortCodes::class);
    app()->forgetInstance(Access::class);

    /** One wrong try from $pad, naming the waiter when $named; returns the HTTP status. */
    $this->wrongTry = fn (string $pad, bool $named): Closure => function () use ($pad, $named): int {
        $body = ['code' => '0000', 'branch_id' => $this->branch->id] + ($named ? ['user_id' => $this->waiter->id] : []);

        return $this->withHeaders(['X-Device-Id' => $pad])->postJson('/api/v1/short-code/verify', $body)->getStatusCode();
    };
    /** @param  list<array{ok: bool, value?: mixed, error?: string}>  $results */
    $this->statuses = function (array $results): array {
        foreach ($results as $result) {
            expect($result['ok'])->toBeTrue($result['error'] ?? '');
        }
        $statuses = array_map(fn (array $r): int => (int) $r['value'], $results);
        sort($statuses);

        return $statuses;
    };
});

it('checks at most 5 codes when ten pads name the same person at once', function () {
    $jobs = array_map(fn (int $i): Closure => ($this->wrongTry)("race-pad-{$i}", true), range(1, 10));

    $statuses = ($this->statuses)(Forks::together($jobs));

    expect($statuses)->toBe([401, 401, 401, 401, 401, 423, 423, 423, 423, 423]);
    $this->waiter->refresh();
    expect($this->waiter->short_code_attempts)->toBe(5)
        ->and($this->waiter->isPinLocked())->toBeTrue();
})->group('concurrency', 'module:identity', 'feature');

it('checks at most 5 codes when one pad sends ten wrong tries at once', function () {
    $jobs = array_map(fn (int $i): Closure => ($this->wrongTry)('pad-1', false), range(1, 10));

    $statuses = ($this->statuses)(Forks::together($jobs));

    expect($statuses)->toBe([401, 401, 401, 401, 401, 423, 423, 423, 423, 423]);
    // the pad stays locked afterwards, even for the right code
    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '4821'])
        ->assertStatus(423)->assertJsonPath('message_key', 'identity.short_code.locked');
})->group('concurrency', 'module:identity', 'feature');
