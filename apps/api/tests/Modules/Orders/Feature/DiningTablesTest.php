<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Models\DiningTable;
use App\Shared\Models\Branch;
use Illuminate\Support\Str;

/*
 * The dining tables of the pad's grid (docs/phases/phase-1-plan.md section 4; step 1.3e): POST and PATCH need
 * tables.manage (admin), GET needs orders.create (the order takers). A table's code is unique per branch (also
 * against an inactive table: orders snapshot it), writes are idempotent and audited, another branch's table is 404.
 * GET returns the stored fields and the derived state in the grid's order (sort, code, id); see DiningTableStateTest for the state (free, ordering, waiting,
 * served, bill) arrives with the read models of step 1.5.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    actInBranch($this->branch);
    withActor($this->admin->id);
    $this->post = function (array $body, ?string $key = null) {
        return $this->withHeaders($key === null ? idempotencyKey() : ['Idempotency-Key' => $key])->postJson('/api/v1/dining-tables', $body);
    };
});

it('creates a table in both languages in the admin branch, audited', function () {
    loginAs($this->admin);

    $response = ($this->post)(['code' => 'T1', 'name_en' => 'Table 1', 'name_am' => 'ጠረጴዛ 1', 'seats' => 4, 'sort' => 10]);

    $response->assertCreated()->assertJson([
        'code' => 'T1', 'name_en' => 'Table 1', 'name_am' => 'ጠረጴዛ 1', 'seats' => 4, 'sort' => 10, 'is_active' => true,
    ]);
    $table = DiningTable::query()->findOrFail($response->json('id'));
    expect($table->branch_id)->toBe($this->branch->id)
        ->and($table->created_by_user_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'dining_table.create')->where('subject_id', $table->id)->exists())->toBeTrue();
})->group('module:orders', 'feature');

it('defaults seats to none and sort to 0 and trims the code', function () {
    loginAs($this->admin);

    ($this->post)(['code' => '  Garden-2 ', 'name_en' => 'Garden 2', 'name_am' => 'የአትክልት 2'])
        ->assertCreated()->assertJson(['code' => 'Garden-2', 'seats' => null, 'sort' => 0, 'is_active' => true]);
})->group('module:orders', 'feature');

it('validates the code, both names, seats and sort', function () {
    loginAs($this->admin);

    $response = ($this->post)(['code' => 'bad code!', 'name_en' => 'Table', 'seats' => 0, 'sort' => -1]);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('code', 'name_am', 'seats', 'sort');

    ($this->post)([])->assertStatus(422);
    expect(DiningTable::query()->count())->toBe(0);
})->group('module:orders', 'feature');

it('refuses a code already used in the branch, even by an inactive table, in both languages, but allows it in another branch', function () {
    loginAs($this->admin);
    DiningTable::factory()->create(['branch_id' => $this->branch->id, 'code' => 'T1']);
    DiningTable::factory()->create(['branch_id' => $this->branch->id, 'code' => 'T2', 'is_active' => false]);
    DiningTable::factory()->create(['branch_id' => $this->other->id, 'code' => 'T9']);

    foreach (['T1', 't1', 'T2'] as $code) {
        $response = ($this->post)(['code' => $code, 'name_en' => 'Again', 'name_am' => 'እንደገና']);
        $response->assertStatus(422)->assertJsonPath('message_key', 'orders.table.code_taken')->assertJsonPath('field', 'code');
        expect($response->json('message_en'))->toBeString()->not->toBe('orders.table.code_taken')
            ->and($response->json('message_am'))->toBeString()->not->toBe($response->json('message_en'));
    }
    ($this->post)(['code' => 'T9', 'name_en' => 'Nine', 'name_am' => 'ዘጠኝ'])->assertCreated();
    expect(DiningTable::query()->count())->toBe(3);
})->group('module:orders', 'feature');

it('replays a create under the same Idempotency-Key and writes one row', function () {
    loginAs($this->admin);
    $key = (string) Str::uuid();
    $body = ['code' => 'T1', 'name_en' => 'Table 1', 'name_am' => 'ጠረጴዛ 1'];

    $first = ($this->post)($body, $key)->assertCreated();
    $second = ($this->post)($body, $key)->assertCreated();

    expect($second->json())->toEqual($first->json())
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(DiningTable::query()->count())->toBe(1);
})->group('module:orders', 'feature');

it('patches names, seats, sort and active, keeps the code, audited', function () {
    loginAs($this->admin);
    $table = DiningTable::factory()->create(['branch_id' => $this->branch->id, 'code' => 'T1', 'seats' => 4, 'sort' => 1]);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/dining-tables/{$table->id}", [
        'name_en' => 'Window', 'name_am' => 'መስኮት', 'seats' => 6, 'sort' => 5, 'is_active' => false,
    ])->assertOk()->assertJson(['id' => $table->id, 'code' => 'T1', 'name_en' => 'Window', 'name_am' => 'መስኮት', 'seats' => 6, 'sort' => 5, 'is_active' => false]);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/dining-tables/{$table->id}", ['seats' => null])->assertOk()->assertJsonPath('seats', null);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/dining-tables/{$table->id}", ['code' => 'T2'])->assertStatus(422);

    $fresh = $table->fresh();
    expect($fresh?->code)->toBe('T1')->and($fresh?->is_active)->toBeFalse()->and($fresh?->seats)->toBeNull()
        ->and(AuditLog::query()->where('action', 'dining_table.update')->where('subject_id', $table->id)->count())->toBe(2);
})->group('module:orders', 'feature');

it('replays a patch under the same Idempotency-Key', function () {
    loginAs($this->admin);
    $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
    $key = (string) Str::uuid();

    $first = $this->withHeaders(['Idempotency-Key' => $key])->patchJson("/api/v1/dining-tables/{$table->id}", ['sort' => 7])->assertOk();
    $second = $this->withHeaders(['Idempotency-Key' => $key])->patchJson("/api/v1/dining-tables/{$table->id}", ['sort' => 7])->assertOk();

    expect($second->json())->toEqual($first->json())->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(AuditLog::query()->where('action', 'dining_table.update')->count())->toBe(1);
})->group('module:orders', 'feature');

it('lists the active tables of the branch in grid order for every order taker, inactive ones on request for the admin', function () {
    $b = $this->branch->id;
    $second = DiningTable::factory()->create(['branch_id' => $b, 'code' => 'T2', 'sort' => 2]);
    $first = DiningTable::factory()->create(['branch_id' => $b, 'code' => 'T10', 'sort' => 1]);
    $tieB = DiningTable::factory()->create(['branch_id' => $b, 'code' => 'B', 'sort' => 2]);
    $off = DiningTable::factory()->create(['branch_id' => $b, 'code' => 'OFF', 'sort' => 0, 'is_active' => false]);
    $foreign = DiningTable::factory()->create(['branch_id' => $this->other->id, 'sort' => 0]);

    foreach (['waiter', 'cashier', 'manager', 'admin'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $rows = $this->getJson('/api/v1/dining-tables')->assertOk()->json('data');
        expect(array_column($rows, 'id'))->toBe([$first->id, $tieB->id, $second->id], $role)
            ->and(array_keys($rows[0]))->toBe(['id', 'code', 'name_en', 'name_am', 'seats', 'sort', 'is_active', 'state'])
            ->and(array_column($rows, 'id'))->not->toContain($foreign->id);
    }

    loginAs($this->admin);
    expect(array_column($this->getJson('/api/v1/dining-tables?include_inactive=1')->assertOk()->json('data'), 'id'))
        ->toBe([$off->id, $first->id, $tieB->id, $second->id]);
    loginAs(makeUser($this->branch, 'waiter'));
    expect(array_column($this->getJson('/api/v1/dining-tables?include_inactive=1')->assertOk()->json('data'), 'id'))
        ->toBe([$first->id, $tieB->id, $second->id]);
})->group('module:orders', 'feature');

it('keeps the roles apart: only the admin writes, the order takers read, the rest are refused', function () {
    $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
    foreach (['manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/dining-tables', ['code' => "X{$role}", 'name_en' => 'x', 'name_am' => 'x'])
            ->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->patchJson("/api/v1/dining-tables/{$table->id}", ['sort' => 3])->assertForbidden();
    }
    foreach (['chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/dining-tables')->assertForbidden();
    }
    expect($table->fresh()?->sort)->toBe(0)->and(DiningTable::query()->count())->toBe(1);
})->group('module:orders', 'feature');

it('treats another branch table as not found and never changes it', function () {
    loginAs($this->admin);
    $foreign = DiningTable::factory()->create(['branch_id' => $this->other->id, 'name_en' => 'Theirs']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/dining-tables/{$foreign->id}", ['name_en' => 'Mine'])->assertNotFound();
    $this->withHeaders(idempotencyKey())->patchJson('/api/v1/dining-tables/999999', ['name_en' => 'Mine'])->assertNotFound();
    expect(DiningTable::query()->withoutGlobalScopes()->findOrFail($foreign->id)->name_en)->toBe('Theirs');
})->group('module:orders', 'feature');
