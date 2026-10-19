<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Locations: where stock is held (rule: stock is per location, kitchen flour and bakery flour are different piles).
 * GET /locations needs inventory.view (every floor role, not the waiter); creating and renaming need inventory.manage
 * (admin). Names are bilingual (rule 9); the type is fixed once created (it decides where availability is computed,
 * e.g. display-case products at the display_case location); a station of another branch is refused; another branch's
 * location is 404 (rule 8). Every write is one AuditedAction behind idempotency.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    actInBranch($this->branch);
    $this->kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
});

it('creates a location in English and Amharic with a station of the branch, audited', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/locations', [
        'name_en' => 'Kitchen', 'name_am' => 'ኩሽና', 'type' => 'kitchen', 'station_id' => $this->kitchenStation->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('name_en', 'Kitchen')
        ->assertJsonPath('name_am', 'ኩሽና')
        ->assertJsonPath('type', 'kitchen')
        ->assertJsonPath('station_id', $this->kitchenStation->id)
        ->assertJsonPath('branch_id', $this->branch->id);

    $location = Location::query()->findOrFail($response->json('id'));
    expect($location->type)->toBe(LocationType::Kitchen)
        ->and(AuditLog::query()->where('action', 'location.create')->where('subject_id', $location->id)->where('actor_id', $this->admin->id)->exists())->toBeTrue();

    // a location without a station: the store, the display case
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/locations', ['name_en' => 'Display case', 'name_am' => 'ማሳያ', 'type' => 'display_case'])
        ->assertCreated()->assertJsonPath('station_id', null);
})->group('module:inventory', 'feature');

it('validates both names, the type and the station of the branch', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/locations', ['name_en' => 'Freezer', 'type' => 'freezer']);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name_am', 'type');

    $foreign = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->other->id]);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/locations', ['name_en' => 'Bar', 'name_am' => 'ባር', 'type' => 'bar', 'station_id' => $foreign->id])
        ->assertStatus(422)->assertJsonValidationErrors(['station_id']);
})->group('module:inventory', 'feature');

it('lists the locations of the branch only, for every role with inventory.view', function () {
    $mine = stockLocation($this->branch, LocationType::Store);
    $kitchen = stockLocation($this->branch, LocationType::Kitchen, $this->kitchenStation);
    $theirs = stockLocation($this->other);

    $ids = array_column($this->getJson('/api/v1/locations')->assertOk()->json('data'), 'id');
    expect($ids)->toEqualCanonicalizing([$mine->id, $kitchen->id])->not->toContain($theirs->id);

    foreach (['manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/locations')->assertOk();
    }
    loginAs(makeUser($this->branch, 'waiter'));
    $this->getJson('/api/v1/locations')->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->group('module:inventory', 'feature');

it('renames a location and moves it to another station, keeping its type, audited', function () {
    $location = stockLocation($this->branch, LocationType::Kitchen);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/locations/{$location->id}", [
        'name_en' => 'Main kitchen', 'name_am' => 'ዋና ኩሽና', 'station_id' => $this->kitchenStation->id,
    ])->assertOk()
        ->assertJsonPath('name_en', 'Main kitchen')
        ->assertJsonPath('name_am', 'ዋና ኩሽና')
        ->assertJsonPath('station_id', $this->kitchenStation->id)
        ->assertJsonPath('type', 'kitchen');

    $row = AuditLog::query()->where('action', 'location.update')->where('subject_id', $location->id)->firstOrFail();
    expect($row->after['name_en'] ?? null)->toBe('Main kitchen');

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/locations/{$location->id}", ['type' => 'store'])
        ->assertStatus(422)->assertJsonValidationErrors(['type']);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/locations/{$location->id}", ['name_am' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['name_am']);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/locations/{$location->id}", ['station_id' => null])
        ->assertOk()->assertJsonPath('station_id', null);
})->group('module:inventory', 'feature');

it('answers 404 for a location of another branch', function () {
    $theirs = stockLocation($this->other);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/locations/{$theirs->id}", ['name_en' => 'Mine'])
        ->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    expect(Location::withoutGlobalScopes()->find($theirs->id)?->name_en)->not->toBe('Mine');
})->group('module:inventory', 'feature');

it('refuses location writes to every role but admin', function () {
    $location = stockLocation($this->branch);
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/locations', ['name_en' => 'X', 'name_am' => 'X', 'type' => 'store'])
            ->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->patchJson("/api/v1/locations/{$location->id}", ['name_en' => 'X'])->assertStatus(403);
    }
    expect(Location::query()->count())->toBe(1);
})->group('module:inventory', 'feature');

it('replays a location create with the same key: one row, the same answer', function () {
    assertIdempotent($this, 'POST', '/api/v1/locations', ['name_en' => 'Bakery', 'name_am' => 'ዳቦ ቤት', 'type' => 'bakery'], 'locations');
})->group('module:inventory', 'feature');
