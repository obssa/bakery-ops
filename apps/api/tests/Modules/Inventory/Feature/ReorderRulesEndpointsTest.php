<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6a (phase-2 plan section 4, "Inventory: reorder and low stock"): GET /reorder-rules (inventory.view), POST /reorder-rules,
 * PATCH /reorder-rules/{rule} and DELETE /reorder-rules/{rule} (inventory.manage, admin), writes behind an Idempotency-Key and
 * one AuditedAction each. Quantities are decimal strings in the item's base unit. One live rule per item and place. A change
 * to a rule re-evaluates the balances it covers (queued, unique), so a lowered minimum closes an episode and a raised one opens
 * one without waiting for the next movement; a deleted or switched-off rule closes its open `low` episode.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    loginAs($this->admin);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->body = fn (array $over = []): array => $over + [
        'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'min_qty' => '5000', 'reorder_qty' => '25000', 'notify_roles' => ['admin', 'manager', 'station'],
    ];
    $this->post = fn (array $over = []) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/reorder-rules', ($this->body)($over));
    $this->makeRule = fn (array $over = []): ReorderRule => ReorderRule::factory()->create($over + [
        'branch_id' => $this->branch->id, 'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id,
    ]);
});

it('creates a rule for an item at a place, audited and stamped with the actor, and answers the rule', function () {
    $response = ($this->post)();

    $response->assertCreated()
        ->assertJsonPath('item.id', $this->flour->id)
        ->assertJsonPath('item.name_en', 'Flour')
        ->assertJsonPath('item.name_am', 'ዱቄት')
        ->assertJsonPath('item.unit', 'g')
        ->assertJsonPath('location.id', $this->store->id)
        ->assertJsonPath('location.name_en', $this->store->name_en)
        ->assertJsonPath('min_qty', '5000.0000')
        ->assertJsonPath('reorder_qty', '25000.0000')
        ->assertJsonPath('notify_roles', ['admin', 'manager', 'station'])
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('version', 1);

    $rule = ReorderRule::query()->findOrFail($response->json('id'));
    expect($rule->branch_id)->toBe($this->branch->id)
        ->and($rule->location_key)->toBe($this->store->id)
        ->and($rule->created_by_user_id)->toBe($this->admin->id)
        ->and($rule->min_qty->toString())->toBe('5000.0000')
        ->and(AuditLog::query()->where('action', 'reorder_rule.create')->where('subject_id', $rule->id)->where('actor_id', $this->admin->id)->exists())->toBeTrue();
});

it('creates a branch-wide rule without a place, a zero minimum, and defaults the roles to admin and manager', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/reorder-rules', ['item_id' => $this->flour->id, 'min_qty' => '0', 'reorder_qty' => '1.5']);

    $response->assertCreated()
        ->assertJsonPath('location', null)
        ->assertJsonPath('min_qty', '0.0000')
        ->assertJsonPath('reorder_qty', '1.5000')
        ->assertJsonPath('notify_roles', ['admin', 'manager']);
    $rule = ReorderRule::query()->findOrFail($response->json('id'));
    expect($rule->location_id)->toBeNull()->and($rule->location_key)->toBe(0);
});

it('validates the fields: decimal strings only, a minimum that is not negative, a reorder quantity above zero', function () {
    foreach ([
        ['item_id' => null], ['item_id' => 'x'], ['min_qty' => null], ['min_qty' => '-1'], ['min_qty' => 5.5], ['min_qty' => '1.23456'], ['min_qty' => 'abc'],
        ['reorder_qty' => null], ['reorder_qty' => '0'], ['reorder_qty' => '-2'], ['reorder_qty' => '12345678901'], ['notify_roles' => 'admin'], ['notify_roles' => [1]], ['is_active' => 'maybe'],
    ] as $bad) {
        $response = ($this->post)($bad);
        $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
        expect(array_keys($response->json('errors')))->not->toBe([], json_encode($bad));
    }
    expect(ReorderRule::query()->count())->toBe(0);
});

it('refuses a role that does not exist, in both languages', function () {
    $response = ($this->post)(['notify_roles' => ['admin', 'baron']]);

    $response->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.role_unknown');
    expect($response->json('message_en'))->not->toBe('inventory.reorder.role_unknown')
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u');
    expect(ReorderRule::query()->count())->toBe(0);
});

it('allows only one live rule per item and place, and a new one once the old one is deleted', function () {
    ($this->post)()->assertCreated();

    ($this->post)()->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.exists');
    ($this->post)(['location_id' => $this->kitchen->id])->assertCreated();
    ($this->post)(['location_id' => null])->assertCreated();
    ($this->post)(['location_id' => null])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.exists');

    $id = ReorderRule::query()->where('location_id', $this->store->id)->firstOrFail()->id;
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/reorder-rules/{$id}")->assertOk();
    ($this->post)()->assertCreated();
    expect(ReorderRule::query()->count())->toBe(3)->and(ReorderRule::withTrashed()->count())->toBe(4);
});

it('refuses an item or a place that is not in this branch, a made-to-order product and a cash drawer', function () {
    $theirs = Item::factory()->ingredient('g')->create(['branch_id' => $this->other->id]);
    $theirPlace = stockLocation($this->other, LocationType::Store);
    $toOrder = Item::factory()->product(Fulfilment::ToOrder, 'pcs')->create(['branch_id' => $this->branch->id]);
    $drawer = stockLocation($this->branch, LocationType::CashDrawer);

    ($this->post)(['item_id' => $theirs->id])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.item_unknown');
    ($this->post)(['item_id' => 999999])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.item_unknown');
    ($this->post)(['item_id' => $toOrder->id])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.item_unknown');
    ($this->post)(['location_id' => $theirPlace->id])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.location_unknown');
    ($this->post)(['location_id' => $drawer->id])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.location_unknown');
    expect(ReorderRule::query()->count())->toBe(0);
});

it('lists the rules of the branch, filtered by item and place, in a stable order', function () {
    $sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Sugar']);
    $a = ($this->makeRule)();
    $b = ($this->makeRule)(['location_id' => $this->kitchen->id, 'location_key' => $this->kitchen->id]);
    $c = ($this->makeRule)(['item_id' => $sugar->id]);
    $theirs = ReorderRule::factory()->create(['branch_id' => $this->other->id]);

    $ids = fn (string $query = ''): array => array_column($this->getJson('/api/v1/reorder-rules'.$query)->assertOk()->json('data'), 'id');

    expect($ids())->toBe([$a->id, $b->id, $c->id])
        ->and($ids("?item_id={$this->flour->id}"))->toBe([$a->id, $b->id])
        ->and($ids("?location_id={$this->kitchen->id}"))->toBe([$b->id])
        ->and($ids("?item_id={$sugar->id}&location_id={$this->kitchen->id}"))->toBe([])
        ->and($ids())->not->toContain($theirs->id);
    expect(array_keys($this->getJson('/api/v1/reorder-rules')->json('data.0')))->toBe(['id', 'item', 'location', 'min_qty', 'reorder_qty', 'notify_roles', 'is_active', 'version']);
});

it('updates the fields of a rule, counts the version, and leaves the item and place alone', function () {
    $rule = ($this->makeRule)(['min_qty' => '5000', 'reorder_qty' => '25000', 'notify_roles' => ['admin']]);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$rule->id}", ['min_qty' => '7500.5', 'reorder_qty' => '30000', 'notify_roles' => ['manager', 'station'], 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('min_qty', '7500.5000')
        ->assertJsonPath('reorder_qty', '30000.0000')
        ->assertJsonPath('notify_roles', ['manager', 'station'])
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('version', 2)
        ->assertJsonPath('item.id', $this->flour->id)
        ->assertJsonPath('location.id', $this->store->id);

    expect(AuditLog::query()->where('action', 'reorder_rule.update')->where('subject_id', $rule->id)->exists())->toBeTrue();

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$rule->id}", ['item_id' => 99, 'location_id' => null, 'min_qty' => '1'])
        ->assertOk()->assertJsonPath('item.id', $this->flour->id)->assertJsonPath('location.id', $this->store->id)->assertJsonPath('min_qty', '1.0000')->assertJsonPath('version', 3);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$rule->id}", ['min_qty' => '1'])->assertOk()->assertJsonPath('version', 3);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$rule->id}", ['reorder_qty' => '0'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$rule->id}", ['notify_roles' => ['baron']])->assertStatus(422)->assertJsonPath('message_key', 'inventory.reorder.role_unknown');
});

it('answers 404 for a rule of another branch or one that was deleted', function () {
    $theirs = ReorderRule::factory()->create(['branch_id' => $this->other->id]);
    $gone = ($this->makeRule)();
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/reorder-rules/{$gone->id}")->assertOk();

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$theirs->id}", ['min_qty' => '1'])->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/reorder-rules/{$theirs->id}")->assertNotFound();
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$gone->id}", ['min_qty' => '1'])->assertNotFound();
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/reorder-rules/{$gone->id}")->assertNotFound();
    expect(ReorderRule::withTrashed()->withoutGlobalScopes()->findOrFail($theirs->id)->deleted_at)->toBeNull();
});

it('soft-deletes a rule, audited, and answers it', function () {
    $rule = ($this->makeRule)();

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/reorder-rules/{$rule->id}")->assertOk()->assertJsonPath('id', $rule->id);

    expect(ReorderRule::query()->count())->toBe(0)
        ->and(ReorderRule::withTrashed()->findOrFail($rule->id)->deleted_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'reorder_rule.delete')->where('subject_id', $rule->id)->exists())->toBeTrue();
    $this->getJson('/api/v1/reorder-rules')->assertOk()->assertJsonPath('data', []);
});

it('replays a create under the same Idempotency-Key and writes it once', function () {
    $headers = idempotencyKey();
    $first = $this->withHeaders($headers)->postJson('/api/v1/reorder-rules', ($this->body)());
    $second = $this->withHeaders($headers)->postJson('/api/v1/reorder-rules', ($this->body)());

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json('id'))->toBe($first->json('id'))->and(ReorderRule::query()->count())->toBe(1);
});

it('lets the floor read the rules and only the admin write them', function () {
    ($this->makeRule)();
    foreach (['manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/reorder-rules')->assertOk();
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/reorder-rules', ($this->body)(['location_id' => $this->kitchen->id]))->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->patchJson('/api/v1/reorder-rules/'.ReorderRule::query()->firstOrFail()->id, ['min_qty' => '1'])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->deleteJson('/api/v1/reorder-rules/'.ReorderRule::query()->firstOrFail()->id)->assertStatus(403);
    }
    loginAs(makeUser($this->branch, 'waiter'));
    $this->getJson('/api/v1/reorder-rules')->assertStatus(403);
    expect(ReorderRule::query()->count())->toBe(1);
});

it('queues one evaluation of the covered balance after a create, and nothing else', function () {
    stockIn($this->flour, $this->store, '1000');
    Queue::fake();

    ($this->post)()->assertCreated();
    assertNothingQueuedButStockEvaluation();
    Queue::assertPushed(EvaluateBalance::class, fn (EvaluateBalance $job): bool => [$job->branchId, $job->itemId, $job->locationId] === [$this->branch->id, $this->flour->id, $this->store->id]);
});

it('evaluates the balances a branch-wide rule covers through one balance of the item', function () {
    stockIn($this->flour, $this->kitchen, '1000');
    stockIn($this->flour, $this->store, '1000');
    Queue::fake();

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/reorder-rules', ['item_id' => $this->flour->id, 'min_qty' => '5000', 'reorder_qty' => '1000'])->assertCreated();

    Queue::assertPushed(EvaluateBalance::class, 1);
});

it('opens a low episode when a rule is created over a balance already below its minimum, and closes it when the rule is lowered, switched off or deleted', function () {
    stockIn($this->flour, $this->store, '400');
    Event::fake([StockLow::class, StockRecovered::class]);

    $created = ($this->post)(['min_qty' => '500'])->assertCreated()->json('id');
    Event::assertDispatchedTimes(StockLow::class, 1);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$created}", ['min_qty' => '300'])->assertOk();
    Event::assertDispatchedTimes(StockRecovered::class, 1);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$created}", ['min_qty' => '800'])->assertOk();
    Event::assertDispatchedTimes(StockLow::class, 2);
    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$created}", ['is_active' => false])->assertOk();
    Event::assertDispatchedTimes(StockRecovered::class, 2);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/reorder-rules/{$created}", ['is_active' => true])->assertOk();
    Event::assertDispatchedTimes(StockLow::class, 3);
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/reorder-rules/{$created}")->assertOk();
    Event::assertDispatchedTimes(StockRecovered::class, 3);
});
