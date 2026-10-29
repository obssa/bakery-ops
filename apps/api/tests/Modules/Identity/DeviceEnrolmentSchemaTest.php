<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\DeviceKind;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

pest()->group('module:identity', 'invariants');

/*
 * Step 5.1a, migration M1 extend_devices_for_enrolment (phase-5 plan 3), asked of the real database: devices gains kind (tablet by
 * default, so every row already there is a tablet), station_id (a home station, informative), last_seen_at, client_uuid (unique per
 * branch), token_hash (a bridge's sha256, unique, and only a bridge may have one) and revoked_by. The CHECKs and UNIQUEs hold on rows
 * written the way Phase 0 wrote them (no kind, no client_uuid, no token): that is what the live rows are.
 */

/** A devices row as Phase 0 wrote it, plus $extra. */
function rawDevice(int $branchId, array $extra = []): int
{
    return (int) DB::table('devices')->insertGetId($extra + [
        'branch_id' => $branchId, 'name' => 'Pad', 'fingerprint' => 'raw-'.Str::uuid(), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('adds the enrolment columns to devices', function () {
    expect(Schema::hasColumns('devices', ['kind', 'station_id', 'last_seen_at', 'client_uuid', 'token_hash', 'revoked_by']))->toBeTrue();
});

it('makes a row written the Phase 0 way a tablet, with nothing else set', function () {
    $id = rawDevice(Branch::factory()->create()->id);
    $row = DB::table('devices')->where('id', $id)->first();

    expect($row->kind)->toBe('tablet')
        ->and($row->station_id)->toBeNull()->and($row->last_seen_at)->toBeNull()->and($row->client_uuid)->toBeNull()
        ->and($row->token_hash)->toBeNull()->and($row->revoked_by)->toBeNull();
});

it('keeps kind to the four kinds of DeviceKind', function () {
    $branch = Branch::factory()->create();
    foreach (DeviceKind::values() as $kind) {
        rawDevice($branch->id, ['kind' => $kind]);
    }
    expect(DeviceKind::values())->toBe(['tablet', 'till', 'kds', 'bridge'])
        ->and(fn () => rawDevice($branch->id, ['kind' => 'toaster']))->toThrow(QueryException::class);
});

it('lets only a bridge carry a token hash, and a hash only once', function () {
    $branch = Branch::factory()->create();
    $hash = hash('sha256', 'a-token');

    rawDevice($branch->id, ['kind' => 'bridge', 'token_hash' => $hash]);
    expect(fn () => rawDevice($branch->id, ['kind' => 'tablet', 'token_hash' => hash('sha256', 'other')]))->toThrow(QueryException::class)
        ->and(fn () => rawDevice($branch->id, ['kind' => 'bridge', 'token_hash' => $hash]))->toThrow(QueryException::class);
});

it('keeps client_uuid unique per branch and lets every Phase 0 row keep a null one', function () {
    $branch = Branch::factory()->create();
    $uuid = (string) Str::uuid();
    rawDevice($branch->id);
    rawDevice($branch->id);
    rawDevice($branch->id, ['client_uuid' => $uuid]);
    rawDevice(Branch::factory()->create()->id, ['client_uuid' => $uuid]);

    expect(fn () => rawDevice($branch->id, ['client_uuid' => $uuid]))->toThrow(QueryException::class);
});

it('points station_id at stations and revoked_by at users', function () {
    $branch = Branch::factory()->create();

    expect(fn () => rawDevice($branch->id, ['station_id' => 999999]))->toThrow(QueryException::class)
        ->and(fn () => rawDevice($branch->id, ['revoked_by' => 999999]))->toThrow(QueryException::class);
});
