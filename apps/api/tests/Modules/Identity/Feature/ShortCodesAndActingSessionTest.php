<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\ActingSession;
use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;

/*
 * The ShortCodes and ActingAsSession capabilities at the service level: 4-6 digits, argon2id, unique per branch,
 * never returned after creation; a session lasts 10 minutes and ends explicitly or by the clock.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->waiter = makeUser($this->branch, 'waiter');
    withActor($this->admin->id);
    $this->codes = app(ShortCodes::class);
});

it('accepts 4 to 6 digits only', function () {
    foreach (['12', '123', 'abcd', '12a4', '1234567', ' 1234', ''] as $bad) {
        expect(fn () => $this->codes->set($this->waiter, $bad))->toThrow(IdentityError::class, 'identity.short_code.format');
    }
    foreach (['1234', '12345', '123456', '0007'] as $good) {
        $this->codes->set($this->waiter, $good);
        expect($this->codes->verify($this->branch->id, $good, 'pad')->id)->toBe($this->waiter->id);
    }
})->group('module:identity', 'feature');

it('hashes with argon2id and never stores or shows the plain code', function () {
    $this->codes->set($this->waiter, '4821');

    $hash = (string) $this->waiter->fresh()?->getAttributes()['short_code_hash'];
    expect($hash)->toStartWith('$argon2id$')
        ->and($hash)->not->toContain('4821')
        ->and($this->waiter->fresh()?->toArray())->not->toHaveKeys(['short_code_hash', 'short_code', 'password'])
        ->and($this->waiter->fresh()?->hasShortCode())->toBeTrue();
})->group('module:identity', 'feature');

it('keeps codes unique per branch, while another branch may reuse the same digits', function () {
    $this->codes->set($this->waiter, '4821');
    $cashier = makeUser($this->branch, 'cashier');

    expect(fn () => $this->codes->set($cashier, '4821'))->toThrow(IdentityError::class, 'identity.short_code.taken');

    // re-setting your own code to the same digits is not a collision
    $this->codes->set($this->waiter, '4821');

    $other = actInBranch(Branch::factory()->create());
    $twin = makeUser($other, 'waiter');
    $this->codes->set($twin, '4821');
    expect($this->codes->verify($other->id, '4821', 'pad')->id)->toBe($twin->id);
})->group('module:identity', 'feature');

it('audits set and reset as redacted user writes', function () {
    $this->codes->set($this->waiter, '4821');
    $new = $this->codes->reset($this->waiter);

    expect($new)->toMatch('/^\d{4,6}$/')->not->toBe('4821');
    $rows = AuditLog::query()->where('subject_id', $this->waiter->id)->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe(['user.short_code.set', 'user.short_code.reset'])
        ->and($rows[0]->after['short_code_hash'] ?? null)->toBe('[redacted]')
        ->and($rows[1]->before['short_code_hash'] ?? null)->toBe('[redacted]')
        ->and($rows[1]->after['short_code_hash'] ?? null)->toBe('[redacted]')
        ->and(json_encode($rows[1]->after))->not->toContain($new);
})->group('module:identity', 'feature');

it('reset() produces a code that no other user of the branch holds', function () {
    // occupy most of a tiny code space to force the generator to skip collisions
    foreach (['1111', '2222'] as $i => $code) {
        $this->codes->set(makeUser($this->branch, 'waiter'), $code);
    }
    $codes = [];
    foreach (range(1, 5) as $i) {
        $codes[] = $this->codes->reset(makeUser($this->branch, 'waiter'));
    }
    expect(array_unique($codes))->toHaveCount(5)
        ->and($codes)->not->toContain('1111', '2222');
})->group('module:identity', 'feature');

it('verify() refuses users without a code and does not count them as candidates', function () {
    expect(fn () => $this->codes->verify($this->branch->id, '4821', 'pad'))->toThrow(IdentityError::class, 'identity.short_code.invalid');
})->group('module:identity', 'feature');

it('start() opens a 10-minute session, current() is what the request resolved, end() closes it', function () {
    $sessions = app(ActingAsSession::class);
    $device = Device::factory()->create(['branch_id' => $this->branch->id]);
    $now = CarbonImmutable::now()->startOfSecond();

    $session = $sessions->start($this->waiter, $device);

    expect($session)->toBeInstanceOf(ActingSession::class)
        ->and($session->user_id)->toBe($this->waiter->id)
        ->and($session->device_id)->toBe($device->id)
        ->and($session->branch_id)->toBe($this->branch->id)
        ->and($session->started_at->greaterThanOrEqualTo($now))->toBeTrue()
        ->and($session->expires_at->equalTo($session->started_at->addMinutes(10)))->toBeTrue()
        ->and($session->isActive())->toBeTrue()
        ->and($sessions->current()?->id)->toBe($session->id)
        ->and($sessions->find($session->id)?->id)->toBe($session->id);

    $sessions->end($session);
    expect($session->fresh()?->ended_at)->not->toBeNull()
        ->and($session->fresh()?->isActive())->toBeFalse()
        ->and($sessions->find($session->id))->toBeNull()
        ->and($sessions->current())->toBeNull();
})->group('module:identity', 'feature');

it('a session expires after 10 minutes by the clock alone', function () {
    $sessions = app(ActingAsSession::class);
    $session = $sessions->start($this->waiter, null);

    $this->travel(9)->minutes();
    expect($sessions->find($session->id))->not->toBeNull();

    $this->travel(1)->minutes();
    $this->travel(1)->seconds();
    expect($sessions->find($session->id))->toBeNull()
        ->and($session->fresh()?->isActive())->toBeFalse()
        ->and($session->fresh()?->ended_at)->toBeNull();
})->group('module:identity', 'feature');
