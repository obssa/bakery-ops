<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\Purchase;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseFixture;

/*
 * POST /purchases/{purchase}/cancel (step 2.2b): only a draft can be cancelled (optional reason), as one AuditedAction. It
 * touches no stock and no cost. A cancelled purchase answers a second cancel with itself (200); a confirmed one is 409
 * purchases.purchase.confirmed (a confirmed purchase is corrected with a stock adjustment, never undone, rule 4).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = PurchaseFixture::build($this->branch);
    loginAs($this->f->admin);
    $this->draft = fn (array $lines = []): int => (int) $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', $this->f->body($lines))->assertCreated()->json('id');
    $this->cancel = fn (int $id, array $body = []) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/cancel", $body);
});

it('cancels a draft with a reason, audited, writing no stock and no cost', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour)]);

    $response = ($this->cancel)($id, ['reason' => 'Supplier did not deliver']);

    $response->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('cancel_reason', 'Supplier did not deliver')
        ->assertJsonPath('version', 2)
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.movement_id', null);
    expect($response->json('cancelled_at'))->not->toBeNull()->and($response->json('confirmed_at'))->toBeNull();

    $purchase = Purchase::query()->findOrFail($id);
    expect($purchase->status->value)->toBe('cancelled')
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('item_costs')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'purchase.cancel')->where('subject_id', $id)->where('actor_id', $this->f->admin->id)->exists())->toBeTrue();
})->group('module:inventory', 'feature');

it('cancels without a reason', function () {
    $id = ($this->draft)();

    ($this->cancel)($id)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancel_reason', null);
})->group('module:inventory', 'feature');

it('answers a second cancel with the cancelled purchase and writes nothing', function () {
    $id = ($this->draft)();
    ($this->cancel)($id, ['reason' => 'first'])->assertOk();
    $audits = AuditLog::query()->count();

    ($this->cancel)($id, ['reason' => 'second'])->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancel_reason', 'first')->assertJsonPath('version', 2);
    expect(AuditLog::query()->count())->toBe($audits);
})->group('module:inventory', 'feature');

it('refuses to cancel a confirmed purchase and leaves it as it was', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour)]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/confirm", [])->assertOk();

    ($this->cancel)($id, ['reason' => 'changed my mind'])
        ->assertStatus(409)->assertJsonPath('message_key', 'purchases.purchase.confirmed')->assertJsonPath('status', 'confirmed')->assertJsonStructure(['message_en', 'message_am']);

    $purchase = Purchase::query()->findOrFail($id);
    expect($purchase->status->value)->toBe('confirmed')->and($purchase->cancelled_at)->toBeNull()->and($purchase->cancel_reason)->toBeNull();
})->group('module:inventory', 'feature');

it('validates the reason', function () {
    $id = ($this->draft)();

    ($this->cancel)($id, ['reason' => str_repeat('r', 256)])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    expect(Purchase::query()->findOrFail($id)->status->value)->toBe('draft');
})->group('module:inventory', 'feature');

it('replays a cancel under the same Idempotency-Key with the same body', function () {
    $id = ($this->draft)();
    $headers = idempotencyKey();

    $a = $this->withHeaders($headers)->postJson("/api/v1/purchases/{$id}/cancel", ['reason' => 'x'])->assertOk();
    $b = $this->withHeaders($headers)->postJson("/api/v1/purchases/{$id}/cancel", ['reason' => 'x'])->assertOk();

    expect($b->headers->get('Idempotency-Replayed'))->toBe('true')->and($b->json())->toBe($a->json())
        ->and(AuditLog::query()->where('action', 'purchase.cancel')->count())->toBe(1);
})->group('module:inventory', 'feature');
