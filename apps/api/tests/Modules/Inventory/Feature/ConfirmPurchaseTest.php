<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Costs\MovingAverage;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\Support\PurchaseFixture;

/*
 * POST /purchases/{purchase}/confirm (step 2.2b; phase-2 plan sections 4 and 6 item 2): one AuditedAction. It locks the
 * purchase, checks it is a draft, writes one purchase_in movement per line at the receiving location in ONE
 * Ledger::appendMany (client_uuid = UUIDv5 of the line's, so a replay cannot receive twice), then CostLedger::receiveMany
 * moves each item's moving average (4 dp), sets movement_id on the lines and the status to confirmed, version + 1. A
 * confirmed purchase is immutable; confirming it again answers 200 with itself and writes nothing.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->f = PurchaseFixture::build($this->branch);
    loginAs($this->f->admin);
    $this->draft = fn (array $lines, array $over = []): int => (int) $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', $this->f->body($lines, $over))->assertCreated()->json('id');
    $this->confirm = fn (int $id) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/confirm", []);
    $this->avg = fn ($item): ?string => DB::table('item_costs')->where('item_id', $item->id)->value('moving_avg_cost');
    $this->movements = fn () => DB::table('stock_movements')->where('reason', 'purchase_in')->orderBy('id')->get();
});

it('writes one purchase_in per line at the store in base units, moves the average, marks the lines and the purchase', function () {
    $flour = $this->f->line($this->f->flour, '20', '80');
    $sugar = $this->f->line($this->f->sugar, '2.5', '120.50');
    $id = ($this->draft)([$flour, $sugar]);

    $response = ($this->confirm)($id);

    $response->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('version', 2)
        ->assertJsonPath('confirmed_by.id', $this->f->admin->id)
        ->assertJsonPath('total', '1901.25')
        ->assertJsonCount(2, 'costs')
        ->assertJsonPath('costs.0.item_id', $this->f->flour->id)
        ->assertJsonPath('costs.0.cost_before', null)
        ->assertJsonPath('costs.0.cost_after', '0.0800')
        ->assertJsonPath('costs.1.item_id', $this->f->sugar->id)
        ->assertJsonPath('costs.1.cost_after', '0.1205');
    expect($response->json('confirmed_at'))->not->toBeNull();

    $rows = ($this->movements)();
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->item_id->toBe($this->f->flour->id)->to_location_id->toBe($this->f->store->id)->from_location_id->toBeNull()->qty->toBe('20000.0000')->ref_type->toBe('purchase')->ref_id->toBe($id)->user_id->toBe($this->f->admin->id)
        ->and($rows[1])->item_id->toBe($this->f->sugar->id)->qty->toBe('2500.0000');

    // the line's movement_id names its row, and the client_uuid is the UUIDv5 of the line's (a replay cannot receive twice)
    foreach ([$flour, $sugar] as $i => $line) {
        $stored = PurchaseLine::query()->where('client_uuid', $line['client_uuid'])->firstOrFail();
        expect($stored->movement_id)->toBe($rows[$i]->id)
            ->and($rows[$i]->client_uuid)->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'purchase-line:'.$this->branch->id.':'.$line['client_uuid'])->toString());
    }

    expect(stockRow($this->f->flour, $this->f->store))->on_hand->toBe('20000.0000')
        ->and(($this->avg)($this->f->flour))->toBe('0.0800')
        ->and(($this->avg)($this->f->sugar))->toBe('0.1205')
        ->and(DB::table('item_cost_history')->count())->toBe(2)
        ->and(DB::table('item_cost_history')->where('item_id', $this->f->flour->id)->first())->source->toBe('purchase')->qty_before->toBe('0.0000')->cost_before->toBeNull()->qty_in->toBe('20000.0000')->unit_cost_in->toBe('0.0800')->cost_after->toBe('0.0800')->user_id->toBe($this->f->admin->id)
        ->and(AuditLog::query()->where('action', 'purchase.confirm')->where('subject_id', $id)->where('actor_id', $this->f->admin->id)->exists())->toBeTrue();

    $purchase = Purchase::query()->findOrFail($id);
    expect($purchase->confirmed_by_user_id)->toBe($this->f->admin->id)->and($purchase->version)->toBe(2);
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('module:inventory', 'feature');

it('moves the moving average to 4 dp over purchases, taking in the stock on hand at every place', function () {
    $first = ($this->draft)([$this->f->line($this->f->flour, '20', '80')]);
    ($this->confirm)($first)->assertOk();
    expect(($this->avg)($this->f->flour))->toBe('0.0800');

    // 10 kg more at 95: (20000 x 0.0800 + 10000 x 0.0950) / 30000 = 0.0850
    $second = ($this->draft)([$this->f->line($this->f->flour, '10', '95')]);
    ($this->confirm)($second)->assertOk()->assertJsonPath('costs.0.cost_before', '0.0800')->assertJsonPath('costs.0.cost_after', '0.0850');
    expect(($this->avg)($this->f->flour))->toBe('0.0850');

    // 7 kg left the store and 3 kg are in the kitchen: the average is still taken over everything on hand (26000 g here)
    stockIn($this->f->flour, $this->f->store, '-4000', MovementReason::Waste);
    stockIn($this->f->flour, $this->f->kitchen, '1000');
    $third = ($this->draft)([$this->f->line($this->f->flour, '4', '100.123')]);
    $expected = MovingAverage::next('27000', '0.0850', '4000', '0.1001')->toString();
    ($this->confirm)($third)->assertOk()->assertJsonPath('costs.0.cost_after', $expected);
    expect(($this->avg)($this->f->flour))->toBe($expected)
        ->and(DB::table('item_cost_history')->where('item_id', $this->f->flour->id)->orderBy('id')->get()->map(fn ($r) => [$r->qty_before, $r->cost_before, $r->cost_after])->all())
        ->toBe([['0.0000', null, '0.0800'], ['20000.0000', '0.0800', '0.0850'], ['27000.0000', '0.0850', $expected]]);
})->group('module:inventory', 'feature');

it('lets stock with no recorded cost stay unvalued: it does not dilute the first purchase', function () {
    stockIn($this->f->flour, $this->f->store, '5000');

    $id = ($this->draft)([$this->f->line($this->f->flour, '10', '60')]);
    ($this->confirm)($id)->assertOk()->assertJsonPath('costs.0.cost_before', null)->assertJsonPath('costs.0.cost_after', '0.0600');

    $history = DB::table('item_cost_history')->where('item_id', $this->f->flour->id)->first();
    expect($history)->qty_before->toBe('5000.0000')->and(($this->avg)($this->f->flour))->toBe('0.0600');
})->group('module:inventory', 'feature');

it('applies several lines of one item in line order, each seeing what the earlier ones added', function () {
    $id = ($this->draft)([
        $this->f->line($this->f->flour, '10', '80'),
        $this->f->line($this->f->sugar, '1', '50'),
        $this->f->line($this->f->flour, '10', '100'),
    ]);

    ($this->confirm)($id)->assertOk()->assertJsonCount(2, 'costs')->assertJsonPath('costs.0.item_id', $this->f->flour->id)->assertJsonPath('costs.0.cost_before', null)->assertJsonPath('costs.0.cost_after', '0.0900');

    $history = DB::table('item_cost_history')->where('item_id', $this->f->flour->id)->orderBy('id')->get();
    expect($history)->toHaveCount(2)
        ->and($history[0])->qty_before->toBe('0.0000')->cost_after->toBe('0.0800')
        ->and($history[1])->qty_before->toBe('10000.0000')->cost_before->toBe('0.0800')->cost_after->toBe('0.0900')
        ->and(($this->movements)())->toHaveCount(3)
        ->and(stockRow($this->f->flour, $this->f->store))->on_hand->toBe('20000.0000');
})->group('module:inventory', 'feature');

it('receives at the location of the purchase, not always the store', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour, '1', '80')], ['location_id' => $this->f->kitchen->id]);

    ($this->confirm)($id)->assertOk()->assertJsonPath('location.id', $this->f->kitchen->id);
    expect(stockRow($this->f->flour, $this->f->kitchen))->on_hand->toBe('1000.0000')->and(stockRow($this->f->flour, $this->f->store))->toBeNull();
})->group('module:inventory', 'feature');

it('answers a second confirm with the purchase as it stands and writes nothing', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour, '20', '80')]);
    $first = ($this->confirm)($id)->assertOk();

    $counts = fn (): array => [
        DB::table('stock_movements')->count(), DB::table('item_cost_history')->count(), AuditLog::query()->count(),
        (int) Purchase::query()->findOrFail($id)->version, (int) DB::table('item_costs')->value('version'),
    ];
    $before = $counts();

    $second = ($this->confirm)($id)->assertOk();

    expect($counts())->toBe($before)
        ->and($second->json('status'))->toBe('confirmed')
        ->and($second->json('confirmed_at'))->toBe($first->json('confirmed_at'))
        ->and($second->json('lines.0.movement_id'))->toBe($first->json('lines.0.movement_id'))
        ->and($second->json('costs'))->toBe($first->json('costs'));
})->group('module:inventory', 'feature');

it('replays a confirm under the same Idempotency-Key with the same body and one set of rows', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour, '20', '80')]);
    $headers = idempotencyKey();

    $a = $this->withHeaders($headers)->postJson("/api/v1/purchases/{$id}/confirm", [])->assertOk();
    $b = $this->withHeaders($headers)->postJson("/api/v1/purchases/{$id}/confirm", [])->assertOk();

    expect($b->headers->get('Idempotency-Replayed'))->toBe('true')->and($b->json())->toBe($a->json())
        ->and(($this->movements)())->toHaveCount(1)->and(DB::table('item_cost_history')->count())->toBe(1);
})->group('module:inventory', 'feature');

it('refuses to confirm a cancelled purchase and an empty one, and writes nothing', function () {
    $cancelled = ($this->draft)([$this->f->line($this->f->flour)]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$cancelled}/cancel", [])->assertOk();
    ($this->confirm)($cancelled)->assertStatus(409)->assertJsonPath('message_key', 'purchases.purchase.cancelled')->assertJsonStructure(['message_en', 'message_am']);

    $empty = ($this->draft)([]);
    ($this->confirm)($empty)->assertStatus(422)->assertJsonPath('message_key', 'purchases.purchase.empty');
    // a draft whose only line was removed is empty too
    $emptied = ($this->draft)([$this->f->line($this->f->flour)]);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$emptied}/lines", ['lines' => []])->assertOk();
    ($this->confirm)($emptied)->assertStatus(422)->assertJsonPath('message_key', 'purchases.purchase.empty');

    expect(DB::table('stock_movements')->count())->toBe(0)->and(DB::table('item_costs')->count())->toBe(0)
        ->and(Purchase::query()->where('status', 'confirmed')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('keeps a confirmed purchase immutable: no new lines, no cancel', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour)]);
    ($this->confirm)($id)->assertOk();

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$id}/lines", ['lines' => [$this->f->line($this->f->sugar)]])
        ->assertStatus(409)->assertJsonPath('message_key', 'purchases.purchase.not_draft');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/cancel", ['reason' => 'oops'])
        ->assertStatus(409)->assertJsonPath('message_key', 'purchases.purchase.confirmed')->assertJsonStructure(['message_en', 'message_am']);

    expect(Purchase::query()->findOrFail($id)->status->value)->toBe('confirmed')->and(PurchaseLine::query()->count())->toBe(1)
        ->and(($this->movements)())->toHaveCount(1);
})->group('module:inventory', 'feature');

it('rolls everything back when the cost cannot be taken: movements, lines, status', function () {
    // the item's average is kept in another currency than the branch buys in
    ItemCost::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $this->f->flour->id, 'currency' => 'USD']);
    $id = ($this->draft)([$this->f->line($this->f->sugar, '1', '10'), $this->f->line($this->f->flour, '20', '80')]);

    ($this->confirm)($id)->assertStatus(409)->assertJsonPath('message_key', 'purchases.cost.currency_mismatch')->assertJsonStructure(['message_en', 'message_am']);

    expect(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('stock_balances')->where('on_hand', '>', 0)->count())->toBe(0)
        ->and(DB::table('item_cost_history')->count())->toBe(0)
        ->and(Purchase::query()->findOrFail($id)->status->value)->toBe('draft')
        ->and(PurchaseLine::query()->whereNotNull('movement_id')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'purchase.confirm')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('queues one balance evaluation per changed balance and nothing else', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour), $this->f->line($this->f->sugar)]);
    Queue::fake();

    ($this->confirm)($id)->assertOk();

    assertNothingQueuedButStockEvaluation();
})->group('module:inventory', 'feature');

it('hides the cost block from a person who holds purchases.record without costs.view', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour)]);
    $cashier = makeUser($this->branch, 'cashier');
    $cashier->givePermissionTo('purchases.record');
    loginAs($cashier);

    $response = ($this->confirm)($id)->assertOk();

    expect($response->json())->not->toHaveKeys(['costs', 'total'])->and($response->json('lines.0'))->not->toHaveKeys(['unit_cost', 'unit_cost_base', 'line_total'])
        ->and($response->json('status'))->toBe('confirmed')->and($response->json('lines.0.movement_id'))->not->toBeNull();
})->group('module:inventory', 'feature');

it('lets the manager confirm', function () {
    $id = ($this->draft)([$this->f->line($this->f->flour)]);
    loginAs($this->f->manager);

    ($this->confirm)($id)->assertOk()->assertJsonPath('confirmed_by.id', $this->f->manager->id)->assertJsonCount(1, 'costs');
})->group('module:inventory', 'feature');

it('treats another branch purchase as not found on every action', function () {
    $theirs = Purchase::factory()->create(['branch_id' => $this->other->id]);

    ($this->confirm)($theirs->id)->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$theirs->id}/cancel", [])->assertNotFound();
    expect(DB::table('stock_movements')->count())->toBe(0);
})->group('module:inventory', 'feature');
