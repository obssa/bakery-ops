<?php

declare(strict_types=1);

use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->group('module:identity', 'feature');

/*
 * Step 3.1a (phase-3 plan section 3, migration 2): stations.tracks_shifts, "does this station run shifts at all?". True by
 * default; the migration backfills false for the warehouse (code `store`) only; PATCH /stations/{station} switches it;
 * POST /stations gives a store false and every other code true unless the request says otherwise.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
});

function tracksShiftsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_24_100200_add_tracks_shifts_to_stations.php');

    return $migration;
}

it('backfills false for the store only, leaving every other station tracking shifts', function () {
    foreach ([StationCode::Kitchen, StationCode::Till, StationCode::Store] as $code) {
        Station::factory()->forCode($code)->create(['branch_id' => $this->branch->id, 'tracks_shifts' => true]);
    }
    // DDL would commit the test's transaction, so the column stays and the migration (which adds it only when missing) runs
    // its backfill over rows that still say true, as the live database's rows will when the column first appears
    tracksShiftsMigration()->up();

    expect(Schema::hasColumn('stations', 'tracks_shifts'))->toBeTrue()
        ->and((int) DB::table('stations')->where('code', 'store')->value('tracks_shifts'))->toBe(0)
        ->and((int) DB::table('stations')->where('code', 'kitchen')->value('tracks_shifts'))->toBe(1)
        ->and((int) DB::table('stations')->where('code', 'till')->value('tracks_shifts'))->toBe(1);
});

it('defaults a new station to tracking shifts', function () {
    $id = DB::table('stations')->insertGetId([
        'branch_id' => $this->branch->id, 'code' => 'bar', 'name_en' => 'Bar', 'name_am' => 'ባር', 'requires_overseer' => 0, 'sort' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect((bool) DB::table('stations')->where('id', $id)->value('tracks_shifts'))->toBeTrue()
        ->and(Station::query()->withoutGlobalScopes()->findOrFail($id)->tracks_shifts)->toBeTrue();
});

it('switches tracks_shifts through PATCH /stations/{station} and shows it in the station resource', function () {
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    loginAs($this->admin);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$kitchen->id}", ['tracks_shifts' => false])
        ->assertOk()->assertJsonPath('tracks_shifts', false);
    expect(Station::query()->findOrFail($kitchen->id)->tracks_shifts)->toBeFalse();

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$kitchen->id}", ['tracks_shifts' => 'maybe'])->assertStatus(422);
    $this->getJson('/api/v1/stations')->assertOk()->assertJsonPath('data.0.tracks_shifts', false);
});

it('creates a store without shifts and any other station with them, unless the request says otherwise', function () {
    loginAs($this->admin);
    $make = fn (string $code, array $extra = []) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/stations', [
        'code' => $code, 'name_en' => ucfirst($code), 'name_am' => 'ጣቢያ',
    ] + $extra);

    $make('store')->assertCreated()->assertJsonPath('tracks_shifts', false);
    $make('bar')->assertCreated()->assertJsonPath('tracks_shifts', true);
    $make('kitchen', ['tracks_shifts' => false])->assertCreated()->assertJsonPath('tracks_shifts', false);
});
