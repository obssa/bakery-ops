<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Models\Branch;

/*
 * Suppliers (step 2.2b; phase-2 plan section 4): GET /suppliers, POST /suppliers, PATCH /suppliers/{supplier}, all behind
 * purchases.record (admin and manager by default), writes behind an Idempotency-Key and one AuditedAction each. A supplier's
 * name is a proper noun (no _en / _am pair). Another branch's supplier is 404 (rule 8). A supplier is switched off with
 * is_active = false, never deleted (the model soft-deletes for completeness; there is no DELETE route).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    loginAs($this->admin);
});

it('creates a supplier, audited, stamped with the actor', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/suppliers', [
        'name' => 'Addis Mill', 'phone' => '+251911000111', 'tin' => '0012345678', 'notes' => 'Delivers on Mondays',
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'Addis Mill')
        ->assertJsonPath('phone', '+251911000111')
        ->assertJsonPath('tin', '0012345678')
        ->assertJsonPath('notes', 'Delivers on Mondays')
        ->assertJsonPath('is_active', true);

    $supplier = Supplier::query()->findOrFail($response->json('id'));
    expect($supplier->branch_id)->toBe($this->branch->id)
        ->and($supplier->created_by_user_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'supplier.create')->where('subject_id', $supplier->id)->where('actor_id', $this->admin->id)->exists())->toBeTrue();

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/suppliers', ['name' => 'Corner Shop'])
        ->assertCreated()->assertJsonPath('phone', null)->assertJsonPath('tin', null);
})->group('module:inventory', 'feature');

it('validates the supplier fields', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/suppliers', ['phone' => str_repeat('1', 40), 'tin' => str_repeat('9', 40), 'notes' => str_repeat('n', 501)]);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name', 'phone', 'tin', 'notes');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/suppliers', ['name' => str_repeat('x', 161)])->assertStatus(422)->assertJsonValidationErrors(['name']);
})->group('module:inventory', 'feature');

it('lists the suppliers of the branch by name, filtered by ?q= and ?active=1', function () {
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Bole Dairy']);
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Addis Mill']);
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Old Mill', 'is_active' => false]);
    Supplier::factory()->create(['branch_id' => $this->other->id, 'name' => 'Their Mill']);

    $names = fn (string $query = ''): array => array_column($this->getJson('/api/v1/suppliers'.$query)->assertOk()->json('data'), 'name');

    expect($names())->toBe(['Addis Mill', 'Bole Dairy', 'Old Mill'])
        ->and($names('?active=1'))->toBe(['Addis Mill', 'Bole Dairy'])
        ->and($names('?q=mill'))->toBe(['Addis Mill', 'Old Mill'])
        ->and($names('?q=mill&active=1'))->toBe(['Addis Mill'])
        ->and($names('?q=zzz'))->toBe([]);
    expect(array_keys($this->getJson('/api/v1/suppliers')->json('data.0')))->toBe(['id', 'name', 'phone', 'tin', 'notes', 'is_active']);
})->group('module:inventory', 'feature');

it('updates a supplier, switches it off, audited; another branch is 404', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Addis Mill']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Addis Flour Mill', 'phone' => '+251911000222', 'is_active' => false])
        ->assertOk()->assertJsonPath('name', 'Addis Flour Mill')->assertJsonPath('phone', '+251911000222')->assertJsonPath('is_active', false);

    expect(AuditLog::query()->where('action', 'supplier.update')->where('subject_id', $supplier->id)->exists())->toBeTrue()
        ->and($supplier->fresh()?->name)->toBe('Addis Flour Mill');

    // a field left out stays; null clears an optional one
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/suppliers/{$supplier->id}", ['phone' => null])->assertOk()->assertJsonPath('phone', null)->assertJsonPath('name', 'Addis Flour Mill');
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/suppliers/{$supplier->id}", ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);

    $theirs = Supplier::factory()->create(['branch_id' => $this->other->id]);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/suppliers/{$theirs->id}", ['name' => 'Hijack'])->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
})->group('module:inventory', 'feature');

it('lets the manager manage suppliers and refuses every role without purchases.record', function () {
    loginAs(makeUser($this->branch, 'manager'));
    $this->getJson('/api/v1/suppliers')->assertOk();
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/suppliers', ['name' => 'By the manager'])->assertCreated();

    foreach (['cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/suppliers')->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/suppliers', ['name' => 'No'])->assertStatus(403);
    }

    // a single permission can be granted to one person
    $cashier = makeUser($this->branch, 'cashier');
    $cashier->givePermissionTo('purchases.record');
    loginAs($cashier);
    $this->getJson('/api/v1/suppliers')->assertOk();
})->group('module:inventory', 'feature');

it('replays a supplier create under the same Idempotency-Key and writes it once', function () {
    $headers = idempotencyKey();
    $first = $this->withHeaders($headers)->postJson('/api/v1/suppliers', ['name' => 'Once']);
    $second = $this->withHeaders($headers)->postJson('/api/v1/suppliers', ['name' => 'Once']);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json('id'))->toBe($first->json('id'))->and(Supplier::query()->where('name', 'Once')->count())->toBe(1);
})->group('module:inventory', 'feature');
