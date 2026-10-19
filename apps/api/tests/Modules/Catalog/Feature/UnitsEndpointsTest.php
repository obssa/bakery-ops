<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Unit;
use App\Shared\Models\Branch;

/*
 * GET/POST /units: the global unit list (no branch_id; units are reference data every branch shares). Reads need
 * catalog.view (every role), writes catalog.manage (admin). A derived unit points at a base unit with a factor
 * (1 sack = 50000 g); a base unit has factor 1. Factors are decimal strings, never floats (ADR 0004).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
});

it('lists the standard units with their base unit and factor as decimal strings', function () {
    $response = $this->getJson('/api/v1/units')->assertOk();

    $byCode = collect($response->json('data'))->keyBy('code');
    expect($byCode->keys()->all())->toContain('kg', 'g', 'L', 'ml', 'pcs', 'slice', 'tray')
        ->and($byCode['kg'])->toMatchArray(['base_unit_code' => 'g', 'factor' => '1000.0000', 'is_base' => false, 'name_am' => 'ኪሎ ግራም'])
        ->and($byCode['g'])->toMatchArray(['base_unit_id' => null, 'base_unit_code' => 'g', 'factor' => '1.0000', 'is_base' => true])
        ->and($byCode['tray'])->toMatchArray(['base_unit_code' => 'pcs', 'factor' => '12.0000']);
})->group('module:catalog', 'feature');

it('lets every role read the units', function () {
    foreach (identityRoles() as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/units')->assertOk();
    }
})->group('module:catalog', 'feature');

it('creates a derived unit on a base unit, audited', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/units', [
        'code' => 'sack', 'name_en' => 'Sack (50 kg)', 'name_am' => 'ጆንያ (50 ኪሎ)', 'base_unit_id' => catalogUnit('g')->id, 'factor' => '50000',
    ]);

    $response->assertCreated()
        ->assertJsonPath('code', 'sack')
        ->assertJsonPath('name_am', 'ጆንያ (50 ኪሎ)')
        ->assertJsonPath('base_unit_code', 'g')
        ->assertJsonPath('factor', '50000.0000')
        ->assertJsonPath('is_base', false);

    expect(AuditLog::query()->where('action', 'unit.create')->where('subject_id', $response->json('id'))->where('actor_id', $this->admin->id)->exists())->toBeTrue();
})->group('module:catalog', 'feature');

it('creates a base unit with factor 1 when no base is named', function () {
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/units', ['code' => 'portion', 'name_en' => 'Portion', 'name_am' => 'ድርሻ'])
        ->assertCreated()
        ->assertJsonPath('base_unit_id', null)
        ->assertJsonPath('base_unit_code', 'portion')
        ->assertJsonPath('factor', '1.0000')
        ->assertJsonPath('is_base', true);
})->group('module:catalog', 'feature');

it('validates both names, a unique code, a base that is itself a base unit and a positive decimal-string factor', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/units', [
        'code' => 'kg', 'name_en' => 'Again', 'base_unit_id' => catalogUnit('kg')->id, 'factor' => 12.5,
    ]);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('code', 'name_am', 'base_unit_id', 'factor');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/units', [
        'code' => 'box', 'name_en' => 'Box', 'name_am' => 'ሳጥን', 'base_unit_id' => catalogUnit('pcs')->id, 'factor' => '0',
    ])->assertStatus(422)->assertJsonValidationErrors(['factor']);

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/units', [
        'code' => 'cup', 'name_en' => 'Cup', 'name_am' => 'ኩባያ', 'factor' => '250',
    ])->assertStatus(422)->assertJsonValidationErrors(['factor']);

    expect(Unit::query()->whereIn('code', ['box', 'cup'])->exists())->toBeFalse();
})->group('module:catalog', 'feature');

it('refuses unit writes to every role but admin', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/units', ['code' => 'x'.$role, 'name_en' => 'x', 'name_am' => 'x'])
            ->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(Unit::query()->where('code', 'like', 'x%')->exists())->toBeFalse();
})->group('module:catalog', 'feature');

it('replays a unit creation idempotently', function () {
    assertIdempotent($this, 'POST', '/api/v1/units', ['code' => 'crate', 'name_en' => 'Crate', 'name_am' => 'ሳጥን', 'base_unit_id' => catalogUnit('pcs')->id, 'factor' => '24'], 'units');
})->group('module:catalog', 'feature');
