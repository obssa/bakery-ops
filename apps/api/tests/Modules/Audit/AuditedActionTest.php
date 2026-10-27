<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Actor\ActorRequired;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;

/*
 * Rule 4 made mechanical: every write goes through AuditedAction::run, which snapshots the subject before and
 * after, stamps who / acting-as / shift / station / device / ip from ActorContext and the request, and writes one
 * audit_log row in the same transaction as the write itself. Audit reads only App\Shared (dependency-graph.md, cycle 4).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id, 'name_en' => 'Kitchen', 'name_am' => 'ኩሽና']);
    withActor(5, actingUserId: 9, stationId: 2, deviceId: 3, shiftInstanceId: 4);
});

it('writes one row with the actor stamp, the subject and before/after snapshots', function () {
    $before = CarbonImmutable::now();

    $result = app(AuditedAction::class)->run(function (Station $station): Station {
        $station->update(['name_en' => 'Hot kitchen']);

        return $station;
    }, $this->station, 'station.rename');

    expect($result)->toBe($this->station)
        ->and(AuditLog::query()->count())->toBe(1);

    $row = AuditLog::query()->firstOrFail();
    expect($row->branch_id)->toBe($this->branch->id)
        ->and($row->actor_id)->toBe(5)
        ->and($row->acting_user_id)->toBe(9)
        ->and($row->station_id)->toBe(2)
        ->and($row->device_id)->toBe(3)
        ->and($row->shift_instance_id)->toBe(4)
        ->and($row->action)->toBe('station.rename')
        ->and($row->subject_type)->toBe($this->station->getMorphClass())
        ->and($row->subject_id)->toBe($this->station->id)
        ->and($row->before)->toBeArray()->toHaveKey('name_en', 'Kitchen')
        ->and($row->after)->toBeArray()->toHaveKey('name_en', 'Hot kitchen')
        ->and($row->after['id'])->toBe($this->station->id)
        ->and($row->context)->toBeNull()
        ->and($row->ip)->toBe('127.0.0.1') // the testing kernel's request, as a real request would carry the client IP
        ->and($row->at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($row->at->greaterThanOrEqualTo($before->startOfSecond()))->toBeTrue()
        ->and($row->at->lessThanOrEqualTo(CarbonImmutable::now()->addSecond()))->toBeTrue();
})->group('module:audit', 'feature');

it('takes the subject from the result when a create has none yet, with a null before snapshot', function () {
    $bar = app(AuditedAction::class)->run(
        fn () => Station::query()->create(['code' => StationCode::Bar, 'name_en' => 'Bar', 'name_am' => 'ባር']),
        null,
        'station.create',
    );

    $row = AuditLog::query()->firstOrFail();
    expect($bar)->toBeInstanceOf(Station::class)
        ->and($row->subject_type)->toBe($bar->getMorphClass())
        ->and($row->subject_id)->toBe($bar->id)
        ->and($row->before)->toBeNull()
        ->and($row->after)->toHaveKey('code', 'bar');
})->group('module:audit', 'feature');

it('records a soft delete as an after snapshot with deleted_at set', function () {
    app(AuditedAction::class)->run(fn (Station $s) => $s->delete(), $this->station, 'station.delete');

    $row = AuditLog::query()->firstOrFail();
    expect($row->before['deleted_at'] ?? null)->toBeNull()
        ->and($row->after['deleted_at'] ?? null)->not->toBeNull()
        ->and(Station::query()->count())->toBe(0);
})->group('module:audit', 'feature');

it('stores the optional context next to the snapshots', function () {
    app(AuditedAction::class)->run(fn () => null, $this->station, 'station.flag', ['reason' => 'oven broken', 'note_en' => 'x', 'note_am' => 'y']);

    // toEqual: MySQL stores JSON object keys in its own order; the pairs are what matter.
    expect(AuditLog::query()->firstOrFail()->context)->toEqual(['reason' => 'oven broken', 'note_en' => 'x', 'note_am' => 'y']);
})->group('module:audit', 'feature');

it('redacts the context too, as it does the snapshots (step 4.5a: before it the context was stored as given)', function () {
    app(AuditedAction::class)->run(fn () => null, $this->station, 'station.flag', [
        'reason' => 'oven broken', 'pin' => '1234', 'rows' => [['reference_no' => 'FT1', 'amount' => '5.00']],
    ]);

    expect(AuditLog::query()->firstOrFail()->context)->toEqual(['reason' => 'oven broken', 'pin' => '[redacted]', 'rows' => [['reference_no' => '[redacted]', 'amount' => '5.00']]]);
})->group('module:audit', 'feature');

it('returns whatever the action returns, not only models', function () {
    $result = app(AuditedAction::class)->run(fn () => ['ok' => true, 'qty' => '12.5000'], $this->station, 'station.noop');

    expect($result)->toBe(['ok' => true, 'qty' => '12.5000'])
        ->and(AuditLog::query()->count())->toBe(1);
})->group('module:audit', 'feature');

it('redacts password, hashes and tokens in both snapshots', function () {
    $user = User::factory()->create(['name' => 'Abebe']);
    $hash = $user->getAttributes()['password'];

    app(AuditedAction::class)->run(function (User $u): void {
        $u->forceFill(['name' => 'Abebe K.', 'remember_token' => 'new-token-value'])->save();
    }, $user, 'user.update');

    $row = AuditLog::query()->firstOrFail();
    expect($row->before['password'])->toBe('[redacted]')
        ->and($row->after['password'])->toBe('[redacted]')
        ->and($row->before['remember_token'])->toBe('[redacted]')
        ->and($row->after['remember_token'])->toBe('[redacted]')
        ->and($row->before['name'])->toBe('Abebe')
        ->and($row->after['name'])->toBe('Abebe K.')
        ->and(json_encode($row->before))->not->toContain($hash)
        ->and(json_encode($row->after))->not->toContain('new-token-value');
})->group('module:audit', 'feature');

it('rolls the audit row back together with the write when the action throws', function () {
    expect(fn () => app(AuditedAction::class)->run(function (Station $station): void {
        $station->update(['name_en' => 'Half done']);
        throw new RuntimeException('oven exploded');
    }, $this->station, 'station.rename'))->toThrow(RuntimeException::class, 'oven exploded');

    expect(AuditLog::query()->count())->toBe(0)
        ->and($this->station->fresh()?->name_en)->toBe('Kitchen');
})->group('module:audit', 'feature');

it('refuses to run without an authenticated actor and does not execute the action', function () {
    app(ActorContext::class)->reset();
    $ran = false;

    expect(fn () => app(AuditedAction::class)->run(function () use (&$ran): void {
        $ran = true;
    }, $this->station, 'station.noop'))->toThrow(ActorRequired::class);

    expect($ran)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
})->group('module:audit', 'feature');

it('records the client IP when the write happens inside a request', function () {
    request()->server->set('REMOTE_ADDR', '10.0.0.7');

    app(AuditedAction::class)->run(fn () => null, $this->station, 'station.noop');

    expect(AuditLog::query()->firstOrFail()->ip)->toBe('10.0.0.7');
})->group('module:audit', 'feature');

it('falls back to the subject branch when no CurrentBranch is resolved (console, seeders)', function () {
    app(CurrentBranch::class)->clear();

    app(AuditedAction::class)->run(fn () => null, $this->station, 'station.noop');

    expect(AuditLog::query()->firstOrFail()->branch_id)->toBe($this->branch->id);
})->group('module:audit', 'feature');

it('refuses a write whose branch cannot be determined', function () {
    app(CurrentBranch::class)->clear();

    expect(fn () => app(AuditedAction::class)->run(fn () => null, null, 'void.event'))->toThrow(BranchNotResolved::class);
    expect(AuditLog::query()->count())->toBe(0);
})->group('module:audit', 'feature');

it('records non-model events with the payload as the after snapshot', function () {
    $row = app(AuditedAction::class)->recordEvent('shift.opened', 'shift_instance', 12, ['station' => 'kitchen', 'opened_by' => 5]);

    expect($row)->toBeInstanceOf(AuditLog::class)
        ->and($row->exists)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(1);

    $fresh = AuditLog::query()->firstOrFail();
    expect($fresh->action)->toBe('shift.opened')
        ->and($fresh->subject_type)->toBe('shift_instance')
        ->and($fresh->subject_id)->toBe(12)
        ->and($fresh->before)->toBeNull()
        ->and($fresh->after)->toEqual(['station' => 'kitchen', 'opened_by' => 5])
        ->and($fresh->actor_id)->toBe(5)
        ->and($fresh->acting_user_id)->toBe(9)
        ->and($fresh->branch_id)->toBe($this->branch->id);
})->group('module:audit', 'feature');

it('records events that have no subject id at all', function () {
    $row = app(AuditedAction::class)->recordEvent('login.failed', 'phone', null, ['phone' => '+2519***']);

    expect($row->subject_id)->toBeNull()
        ->and($row->after)->toBe(['phone' => '+2519***']);
})->group('module:audit', 'feature');
