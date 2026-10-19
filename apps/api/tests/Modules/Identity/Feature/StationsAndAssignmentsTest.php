<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Stations (Shared's table, managed from People) and user_station_assignments: who may count what, who co-signs.
 * PUT /users/{id}/stations replaces the user's assignments as one audited write; removed rows are soft-deleted and
 * restored when the station comes back (rule 4: nothing is deleted in place).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    $this->other = Branch::factory()->create();
    loginAs($this->admin);
});

it('lists the branch stations only', function () {
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->other->id]);

    $response = $this->getJson('/api/v1/stations')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$kitchen->id])
        ->and($response->json('data.0'))->toMatchArray(['code' => 'kitchen', 'name_en' => 'Kitchen', 'name_am' => 'ኩሽና', 'requires_overseer' => false]);
})->group('module:identity', 'feature');

it('creates a station with bilingual names in the admin branch, audited', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/stations', [
        'code' => 'cake_room', 'name_en' => 'Cake room', 'name_am' => 'የኬክ ክፍል', 'requires_overseer' => true, 'sort' => 4,
    ]);

    $response->assertCreated()
        ->assertJsonPath('code', 'cake_room')
        ->assertJsonPath('name_en', 'Cake room')
        ->assertJsonPath('name_am', 'የኬክ ክፍል')
        ->assertJsonPath('requires_overseer', true)
        ->assertJsonPath('sort', 4);

    $station = Station::query()->withoutGlobalScopes()->findOrFail($response->json('id'));
    expect($station->branch_id)->toBe($this->branch->id)
        ->and(AuditLog::query()->where('action', 'station.create')->where('subject_id', $station->id)->exists())->toBeTrue();
})->group('module:identity', 'feature');

it('validates the station code, both names and uniqueness per branch', function () {
    Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/stations', ['code' => 'garage', 'name_en' => 'x']);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('code', 'name_am');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/stations', ['code' => 'bar', 'name_en' => 'Bar', 'name_am' => 'ባር'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', fn (string $m) => $m !== '');

    // the same code in another branch is free
    expect(Station::query()->withoutGlobalScopes()->where('branch_id', $this->other->id)->count())->toBe(0);
})->group('module:identity', 'feature');

it('replaces a user\'s station assignments, soft-deleting removed rows and restoring returning ones', function () {
    $chef = makeUser($this->branch, 'chef');
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$chef->id}/stations", ['assignments' => [
        ['station_id' => $kitchen->id, 'can_count' => true, 'is_overseer' => false],
    ]])->assertOk()->assertJsonPath('assignments', [['station_id' => $kitchen->id, 'can_count' => true, 'is_overseer' => false]]);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$chef->id}/stations", ['assignments' => [
        ['station_id' => $bar->id, 'can_count' => false, 'is_overseer' => true],
    ]])->assertOk()->assertJsonPath('assignments', [['station_id' => $bar->id, 'can_count' => false, 'is_overseer' => true]]);

    expect(UserStationAssignment::query()->where('user_id', $chef->id)->count())->toBe(1)
        ->and(UserStationAssignment::query()->withTrashed()->where('user_id', $chef->id)->count())->toBe(2);

    // the kitchen comes back: the soft-deleted row is restored, no unique-pair violation
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$chef->id}/stations", ['assignments' => [
        ['station_id' => $kitchen->id, 'can_count' => true, 'is_overseer' => true],
        ['station_id' => $bar->id, 'can_count' => true, 'is_overseer' => false],
    ]])->assertOk();

    expect(UserStationAssignment::query()->withTrashed()->where('user_id', $chef->id)->count())->toBe(2)
        ->and(UserStationAssignment::query()->where('user_id', $chef->id)->where('station_id', $kitchen->id)->firstOrFail()->is_overseer)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.stations.replace')->where('subject_id', $chef->id)->count())->toBe(3);
})->group('module:identity', 'feature');

it('refuses a station of another branch and validates the payload shape', function () {
    $chef = makeUser($this->branch, 'chef');
    $foreign = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->other->id]);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$chef->id}/stations", ['assignments' => [
        ['station_id' => $foreign->id, 'can_count' => true, 'is_overseer' => false],
    ]])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$chef->id}/stations", ['assignments' => 'kitchen'])
        ->assertStatus(422);

    expect(UserStationAssignment::query()->withTrashed()->count())->toBe(0);
})->group('module:identity', 'feature');

it('is forbidden for every role but admin', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        $user = loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/stations')->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/stations', [])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$user->id}/stations", ['assignments' => []])->assertStatus(403);
    }
})->group('module:identity', 'feature');

it('replays a station creation idempotently', function () {
    assertIdempotent($this, 'POST', '/api/v1/stations', ['code' => 'store', 'name_en' => 'Store', 'name_am' => 'መጋዘን'], 'stations');
})->group('module:identity', 'feature');
