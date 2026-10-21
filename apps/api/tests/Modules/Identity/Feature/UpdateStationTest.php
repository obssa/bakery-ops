<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

pest()->group('module:identity', 'feature');

/*
 * Step 2.7a (phase-2 plan section 4, "Identity (2.7a)"): PATCH /stations/{station} changes the names, requires_overseer and
 * sort of a station of the admin's branch; the code is fixed. It is how the cake room is made to need an overseer before a
 * batch counts (Production), so the plan's e2e flow sets it through this endpoint. One audited write, an Idempotency-Key,
 * people.manage only.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    $this->other = Branch::factory()->create();
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $this->branch->id, 'sort' => 3]);
    loginAs($this->admin);
});

it('sets requires_overseer and leaves the other fields as they were, audited with before and after', function () {
    $response = $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['requires_overseer' => true]);

    $response->assertOk()
        ->assertJsonPath('id', $this->cakeRoom->id)
        ->assertJsonPath('code', 'cake_room')
        ->assertJsonPath('requires_overseer', true)
        ->assertJsonPath('name_en', $this->cakeRoom->name_en)
        ->assertJsonPath('name_am', $this->cakeRoom->name_am)
        ->assertJsonPath('sort', 3);

    $fresh = Station::query()->findOrFail($this->cakeRoom->id);
    $audit = AuditLog::query()->where('action', 'station.update')->where('subject_id', $this->cakeRoom->id)->sole();
    expect($fresh->requires_overseer)->toBeTrue()
        ->and($audit->before['requires_overseer'])->toBeIn([0, false])
        ->and($audit->after['requires_overseer'])->toBeIn([1, true])
        ->and($audit->actor_id)->toBe($this->admin->id);
});

it('changes the names and the sort, and can switch the overseer off again', function () {
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['requires_overseer' => true])->assertOk();

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", [
        'name_en' => 'Cake kitchen', 'name_am' => 'የኬክ ማብሰያ', 'sort' => 9, 'requires_overseer' => false,
    ])->assertOk()
        ->assertJsonPath('name_en', 'Cake kitchen')
        ->assertJsonPath('name_am', 'የኬክ ማብሰያ')
        ->assertJsonPath('sort', 9)
        ->assertJsonPath('requires_overseer', false);

    expect(Station::query()->findOrFail($this->cakeRoom->id)->name_am)->toBe('የኬክ ማብሰያ')
        ->and(AuditLog::query()->where('action', 'station.update')->where('subject_id', $this->cakeRoom->id)->count())->toBe(2);
});

it('keeps the code fixed: sending one is refused and nothing changes', function () {
    $response = $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['code' => 'bar', 'sort' => 5]);

    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toBe(['code'])
        ->and(Station::query()->findOrFail($this->cakeRoom->id)->code)->toBe(StationCode::CakeRoom)
        ->and(Station::query()->findOrFail($this->cakeRoom->id)->sort)->toBe(3)
        ->and(AuditLog::query()->where('action', 'station.update')->count())->toBe(0);
});

it('validates the names, the flag and the sort', function () {
    $response = $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", [
        'name_en' => '', 'name_am' => str_repeat('x', 121), 'requires_overseer' => 'maybe', 'sort' => -1,
    ]);

    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toEqualCanonicalizing(['name_en', 'name_am', 'requires_overseer', 'sort']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['sort' => 65536])->assertStatus(422);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['name_en' => null])->assertStatus(422);
});

it('answers the validation message in the reader language', function () {
    $en = $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['sort' => -1])->assertStatus(422);
    $am = $this->withHeaders(idempotencyKey() + ['Accept-Language' => 'am'])->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['sort' => -1])->assertStatus(422);

    expect($en->json('message_en'))->not->toBe('')
        ->and($en->json('message_am'))->not->toBe('')
        ->and($am->json('message_en'))->toBe($en->json('message_en'))
        ->and($am->json('message_am'))->toBe($en->json('message_am'));
});

it('is a 404 for a station of another branch, a deleted one, or one that does not exist', function () {
    $theirs = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->other->id]);
    $gone = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->branch->id]);
    $gone->delete();

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$theirs->id}", ['sort' => 1])->assertNotFound();
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$gone->id}", ['sort' => 1])->assertNotFound();
    $this->withHeaders(idempotencyKey())->patchJson('/api/v1/stations/999999', ['sort' => 1])->assertNotFound();

    expect(Station::query()->withoutGlobalScopes()->findOrFail($theirs->id)->sort)->not->toBe(1);
});

it('needs the people.manage permission and an Idempotency-Key', function () {
    loginAs(makeUser($this->branch, 'manager'));
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['requires_overseer' => true])->assertForbidden();

    loginAs($this->admin);
    $this->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['requires_overseer' => true])->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
    expect(Station::query()->findOrFail($this->cakeRoom->id)->requires_overseer)->toBeFalse();
});

it('answers a replay of the same key with the first result and writes one audit row', function () {
    $headers = idempotencyKey();
    $first = $this->withHeaders($headers)->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['requires_overseer' => true])->assertOk();
    $second = $this->withHeaders($headers)->patchJson("/api/v1/stations/{$this->cakeRoom->id}", ['requires_overseer' => true])->assertOk();

    expect($second->json())->toBe($first->json())
        ->and(AuditLog::query()->where('action', 'station.update')->count())->toBe(1);
});
