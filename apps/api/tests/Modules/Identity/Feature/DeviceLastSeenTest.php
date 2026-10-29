<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\DeviceLastSeen;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

pest()->group('module:identity', 'feature');

/*
 * Step 5.1a (phase-5 plan 4.1 and 10.8): with the flag on, the device check records when a tablet was last seen, at most once a minute
 * per device. A request that loaded a row seen less than a minute ago writes nothing; requests that all loaded a stale row at once (a
 * burst after a quiet minute) write once, because the write takes a cache lock that lives a minute (the default store: `database` on
 * the live host, which has no Redis). The write touches last_seen_at only: no updated_at, no audit row (it is not a person's change).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->pad = Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-seen']);
    loginAs(makeUser($this->branch, 'waiter'));
    config(['identity.require_enrolled_device' => true]);
});

/** How many UPDATEs of devices.last_seen_at $run makes. */
function lastSeenWrites(Closure $run): int
{
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^update\s+[`"]?devices[`"]?\s+set\s+.*last_seen_at/i', $query->sql) === 1) {
            $writes++;
        }
    });
    $run();

    return $writes;
}

it('writes last_seen_at once under 30 requests in a minute', function () {
    $writes = lastSeenWrites(function () {
        for ($i = 0; $i < 30; $i++) {
            $this->withHeaders([ResolveActor::DEVICE_HEADER => 'pad-seen'])->getJson('/api/v1/dining-tables')->assertOk();
        }
    });

    expect($writes)->toBe(1);
    expect($this->pad->fresh()?->last_seen_at?->diffInSeconds(CarbonImmutable::now(), true))->toBeLessThan(2.0);
});

it('writes again once a minute has passed, not before', function () {
    $call = fn () => $this->withHeaders([ResolveActor::DEVICE_HEADER => 'pad-seen'])->getJson('/api/v1/dining-tables')->assertOk();

    expect(lastSeenWrites($call))->toBe(1);
    $first = $this->pad->fresh()?->last_seen_at;

    $this->travel(30)->seconds();
    expect(lastSeenWrites($call))->toBe(0);

    $this->travel(31)->seconds();
    expect(lastSeenWrites($call))->toBe(1);
    expect($this->pad->fresh()?->last_seen_at?->greaterThan($first))->toBeTrue();
});

it('writes once when 30 requests all read the row before any of them wrote', function (?string $store) {
    if ($store !== null) {
        config(['cache.default' => $store]);
    }
    $stale = Device::query()->findOrFail($this->pad->id);
    expect($stale->last_seen_at)->toBeNull();

    $writes = lastSeenWrites(function () use ($stale) {
        for ($i = 0; $i < 30; $i++) {
            app(DeviceLastSeen::class)->touch($stale);
        }
    });

    expect($writes)->toBe(1);
})->with([
    'the default store of the test run' => [null],
    'the database store, as on the live host' => ['database'],
]);

it('writes nothing while the flag is off, and nothing on a route that is not a station route', function () {
    $writes = lastSeenWrites(function () {
        $this->withHeaders([ResolveActor::DEVICE_HEADER => 'pad-seen'])->getJson('/api/v1/me')->assertOk();
        config(['identity.require_enrolled_device' => false]);
        $this->withHeaders([ResolveActor::DEVICE_HEADER => 'pad-seen'])->getJson('/api/v1/dining-tables')->assertOk();
    });

    expect($writes)->toBe(0)->and($this->pad->fresh()?->last_seen_at)->toBeNull();
});

it('moves neither updated_at nor the audit log', function () {
    $updatedAt = $this->pad->fresh()?->updated_at;
    $audit = AuditLog::query()->count();
    $this->travel(5)->minutes();

    $this->withHeaders([ResolveActor::DEVICE_HEADER => 'pad-seen'])->getJson('/api/v1/dining-tables')->assertOk();

    expect($this->pad->fresh()?->last_seen_at)->not->toBeNull()
        ->and($this->pad->fresh()?->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($audit);
});
