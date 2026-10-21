<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PurchaseFixture;

/*
 * Purchase drafts (step 2.2b; phase-2 plan section 4): POST /purchases records a draft, PUT /purchases/{purchase}/lines
 * replaces a draft's lines by line client_uuid, GET /purchases and GET /purchases/{purchase} read them. A draft writes no
 * movement and no cost. The typed quantity and cost are converted to the item's base unit here (Units::toBase,
 * UnitCost::dividedBy, half-up to 4 dp), the line total is qty x typed cost half-up to 2 dp, the purchase total is the sum.
 * The purchase's client_uuid makes an offline replay with a lost Idempotency-Key reach the same purchase (ADR 0003).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->other = Branch::factory()->create();
    $this->f = PurchaseFixture::build($this->branch);
    loginAs($this->f->admin);
    $this->post = fn (array $body) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', $body);
});

it('records a draft: converts kilograms to grams, totals the lines, defaults to the store, writes no stock and no cost', function () {
    $body = $this->f->body([
        $this->f->line($this->f->flour, '20', '80'),
        $this->f->line($this->f->sugar, '2.5', '120.50'),
    ], ['notes' => 'Monday delivery']);

    $response = ($this->post)($body);

    $response->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('client_uuid', $body['client_uuid'])
        ->assertJsonPath('supplier.id', $this->f->supplier->id)
        ->assertJsonPath('supplier.name', 'Addis Mill')
        ->assertJsonPath('invoice_no', $body['invoice_no'])
        ->assertJsonPath('purchase_date', $body['purchase_date'])
        ->assertJsonPath('location.id', $this->f->store->id)
        ->assertJsonPath('currency', 'ETB')
        ->assertJsonPath('total', '1901.25')
        ->assertJsonPath('notes', 'Monday delivery')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('confirmed_at', null)
        ->assertJsonPath('cancelled_at', null)
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0.client_uuid', $body['lines'][0]['client_uuid'])
        ->assertJsonPath('lines.0.item.id', $this->f->flour->id)
        ->assertJsonPath('lines.0.item.name_en', 'Flour')
        ->assertJsonPath('lines.0.qty', '20.0000')
        ->assertJsonPath('lines.0.unit.code', 'kg')
        ->assertJsonPath('lines.0.qty_base', '20000.0000')
        ->assertJsonPath('lines.0.unit_cost', '80.0000')
        ->assertJsonPath('lines.0.unit_cost_base', '0.0800')
        ->assertJsonPath('lines.0.line_total', '1600.00')
        ->assertJsonPath('lines.0.movement_id', null)
        ->assertJsonPath('lines.1.qty_base', '2500.0000')
        ->assertJsonPath('lines.1.unit_cost_base', '0.1205')
        ->assertJsonPath('lines.1.line_total', '301.25');

    $purchase = Purchase::query()->findOrFail($response->json('id'));
    expect($purchase->branch_id)->toBe($this->branch->id)
        ->and($purchase->created_by_user_id)->toBe($this->f->admin->id)
        ->and(PurchaseLine::query()->where('purchase_id', $purchase->id)->count())->toBe(2)
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('item_costs')->count())->toBe(0)
        ->and(DB::table('item_cost_history')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'purchase.create')->where('subject_id', $purchase->id)->where('actor_id', $this->f->admin->id)->exists())->toBeTrue();
})->group('module:inventory', 'feature');

it('accepts a draft without lines, a bought-in product, a zero cost, and a location other than the store', function () {
    ($this->post)($this->f->body([]))->assertCreated()->assertJsonCount(0, 'lines')->assertJsonPath('total', '0.00');

    $response = ($this->post)($this->f->body([$this->f->line($this->f->juice, '24', '0'), $this->f->line($this->f->flour, '1', '5')], ['location_id' => $this->f->kitchen->id]));
    $response->assertCreated()
        ->assertJsonPath('location.id', $this->f->kitchen->id)
        ->assertJsonPath('lines.0.unit.code', 'pcs')
        ->assertJsonPath('lines.0.unit_cost_base', '0.0000')
        ->assertJsonPath('lines.0.line_total', '0.00')
        ->assertJsonPath('total', '5.00');
})->group('module:inventory', 'feature');

it('keeps the same item on two lines and converts a line typed in grams for a kilogram item', function () {
    $response = ($this->post)($this->f->body([
        $this->f->line($this->f->flour, '500', '0.1', $this->f->g),
        $this->f->line($this->f->flour, '1', '100'),
    ]));

    $response->assertCreated()
        ->assertJsonPath('lines.0.unit.code', 'g')
        ->assertJsonPath('lines.0.qty_base', '500.0000')
        ->assertJsonPath('lines.0.unit_cost_base', '0.1000')
        ->assertJsonPath('lines.0.line_total', '50.00')
        ->assertJsonPath('lines.1.qty_base', '1000.0000')
        ->assertJsonPath('lines.1.unit_cost_base', '0.1000')
        ->assertJsonPath('total', '150.00');
})->group('module:inventory', 'feature');

it('answers a client_uuid already used with that purchase, 200, writing nothing, even under a new Idempotency-Key', function () {
    $body = $this->f->body([$this->f->line($this->f->flour)]);

    $first = ($this->post)($body)->assertCreated();
    $again = ($this->post)($body)->assertOk();

    expect($again->json('id'))->toBe($first->json('id'))->and($again->json('lines.0.id'))->toBe($first->json('lines.0.id'))
        ->and(Purchase::query()->where('client_uuid', $body['client_uuid'])->count())->toBe(1)
        ->and(PurchaseLine::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'purchase.create')->count())->toBe(1);

    // the same key replays the stored answer
    $headers = idempotencyKey();
    $other = $this->f->body([$this->f->line($this->f->sugar)]);
    $a = $this->withHeaders($headers)->postJson('/api/v1/purchases', $other);
    $b = $this->withHeaders($headers)->postJson('/api/v1/purchases', $other);
    expect($b->headers->get('Idempotency-Replayed'))->toBe('true')->and($b->json('id'))->toBe($a->json('id'))
        ->and(Purchase::query()->count())->toBe(2);
})->group('module:inventory', 'feature');

it('validates the body field by field', function () {
    $response = ($this->post)(['lines' => [['qty' => 1.5, 'unit_cost' => 'abc']]]);
    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('client_uuid', 'supplier_id', 'purchase_date', 'lines.0.client_uuid', 'lines.0.item_id', 'lines.0.qty', 'lines.0.unit_id', 'lines.0.unit_cost');

    $line = $this->f->line($this->f->flour);
    foreach ([
        'purchase_date' => ['2026-13-40', 'purchase_date'],
        'invoice_no' => [str_repeat('i', 65), 'invoice_no'],
        'notes' => [str_repeat('n', 501), 'notes'],
        'client_uuid' => ['not-a-uuid', 'client_uuid'],
    ] as [$value, $field]) {
        ($this->post)($this->f->body([$line], [$field => $value, 'client_uuid' => $field === 'client_uuid' ? $value : (string) Str::uuid()]))
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
    }

    // a float never gets in, a quantity over 4 decimals or 10 integer digits, a negative or zero quantity, a cost over 4 decimals
    foreach ([['qty', 1.5], ['qty', '1.00001'], ['qty', '12345678901'], ['qty', '0'], ['qty', '-1'], ['unit_cost', 2.5], ['unit_cost', '1.00001'], ['unit_cost', '-1']] as [$field, $value]) {
        $bad = $line;
        $bad[$field] = $value;
        ($this->post)($this->f->body([$bad]))->assertStatus(422)->assertJsonValidationErrors(["lines.0.{$field}"]);
    }

    // two lines with one client_uuid
    $dup = [$line, $this->f->line($this->f->sugar, uuid: $line['client_uuid'])];
    ($this->post)($this->f->body($dup))->assertStatus(422)->assertJsonValidationErrors(['lines.1.client_uuid']);

    // too many lines
    $many = array_map(fn () => $this->f->line($this->f->flour, '1', '1'), range(1, 101));
    ($this->post)($this->f->body($many))->assertStatus(422)->assertJsonValidationErrors(['lines']);
    expect(Purchase::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a supplier or location that is not live in this branch, with a bilingual message', function () {
    $foreign = Supplier::factory()->create(['branch_id' => $this->other->id]);
    $off = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);
    $gone = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $gone->delete();

    foreach ([$foreign, $off, $gone] as $supplier) {
        ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['supplier_id' => $supplier->id]))
            ->assertStatus(422)->assertJsonPath('message_key', 'purchases.supplier.unknown')
            ->assertJsonStructure(['message_en', 'message_am']);
    }

    $theirs = Location::factory()->create(['branch_id' => $this->other->id]);
    $response = ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['location_id' => $theirs->id]));
    $response->assertStatus(422)->assertJsonPath('message_key', 'purchases.location.unknown');
    expect($response->json('message_en'))->not->toBe('purchases.location.unknown')->and($response->json('message_am'))->not->toBe($response->json('message_en'));
    expect(Purchase::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('needs a store when no location is named', function () {
    Location::query()->where('type', 'store')->each(fn (Location $l) => $l->delete());

    ($this->post)($this->f->body([$this->f->line($this->f->flour)]))->assertStatus(422)->assertJsonPath('message_key', 'purchases.location.unknown');
})->group('module:inventory', 'feature');

it('buys only ingredients and bought-in products, and only live items of this branch', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    $latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    $theirs = Item::factory()->ingredient('kg')->create(['branch_id' => $this->other->id]);
    $gone = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    $gone->delete();

    foreach ([$cake, $latte, $theirs, $gone] as $item) {
        $line = $this->f->line($this->f->flour);
        $line['item_id'] = $item->id;
        $line['unit_id'] = $this->f->kg->id;
        $response = ($this->post)($this->f->body([$this->f->line($this->f->sugar), $line]));
        $response->assertStatus(422)->assertJsonPath('message_key', 'purchases.item.not_purchasable')->assertJsonPath('line', 1)->assertJsonPath('item_id', $item->id);
    }
    expect(Purchase::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a unit that does not convert to the item base unit', function () {
    $line = $this->f->line($this->f->flour, '3', '10', catalogUnit('L'));

    ($this->post)($this->f->body([$line]))->assertStatus(422)->assertJsonPath('message_key', 'purchases.unit.incompatible')->assertJsonPath('line', 0)->assertJsonPath('field', 'unit_id');
    expect(Purchase::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a cost that rounds to zero per base unit while the typed cost is not zero', function () {
    // 0.0001 ETB per kg is 0.0000001 per gram: below the 4 dp the average is kept in
    $tiny = $this->f->line($this->f->flour, '3', '0.0001');
    ($this->post)($this->f->body([$tiny]))->assertStatus(422)->assertJsonPath('message_key', 'purchases.line.cost_too_small')->assertJsonPath('line', 0);

    // 0.05 per kg is 0.00005 per gram: rounds half up to 0.0001, so it is recorded
    ($this->post)($this->f->body([$this->f->line($this->f->flour, '3', '0.05')]))->assertCreated()->assertJsonPath('lines.0.unit_cost_base', '0.0001');
    // 0.04 per kg is 0.00004 per gram: rounds to 0.0000
    ($this->post)($this->f->body([$this->f->line($this->f->flour, '3', '0.04')]))->assertStatus(422)->assertJsonPath('message_key', 'purchases.line.cost_too_small');
})->group('module:inventory', 'feature');

it('refuses a total, line or base quantity that does not fit its column', function () {
    // 9 999 999 999 kg is 9 999 999 999 000 g: more than the ten integer digits of a quantity
    ($this->post)($this->f->body([$this->f->line($this->f->flour, '9999999999', '1')]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    // a line total over ten integer digits
    ($this->post)($this->f->body([$this->f->line($this->f->juice, '9999999999', '9999999999')]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.unit_cost']);
    // lines that fit one by one but whose sum does not
    $big = fn () => $this->f->line($this->f->juice, '1', '9000000000');
    ($this->post)($this->f->body([$big(), $big()]))->assertStatus(422)->assertJsonValidationErrors(['lines']);
    expect(Purchase::query()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a second purchase with the same supplier and invoice number unless told otherwise; cancelled ones do not count', function () {
    $first = ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => 'INV-100']))->assertCreated();

    $dup = ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => 'INV-100']));
    $dup->assertStatus(409)->assertJsonPath('message_key', 'purchases.invoice.duplicate')->assertJsonPath('purchase_id', $first->json('id'));

    ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => 'INV-100', 'allow_duplicate_invoice' => true]))->assertCreated();

    $other = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => 'INV-100', 'supplier_id' => $other->id]))->assertCreated();
    // no invoice number, no check
    ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => null]))->assertCreated();
    ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => null]))->assertCreated();

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases/'.$first->json('id').'/cancel', [])->assertOk();
    ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => 'INV-200']))->assertCreated();
    Purchase::query()->where('invoice_no', 'INV-100')->where('supplier_id', $this->f->supplier->id)->where('status', 'draft')->update(['invoice_no' => 'INV-OLD']);
    ($this->post)($this->f->body([$this->f->line($this->f->flour)], ['invoice_no' => 'INV-100']))->assertCreated();
})->group('module:inventory', 'feature');

it('replaces the lines of a draft by client_uuid: keeps, updates, drops (soft-deleted) and adds, and retotals', function () {
    $keep = $this->f->line($this->f->flour, '20', '80');
    $drop = $this->f->line($this->f->sugar, '5', '100');
    $created = ($this->post)($this->f->body([$keep, $drop]))->assertCreated();
    $id = $created->json('id');
    $keptRowId = $created->json('lines.0.id');
    expect($created->json('total'))->toBe('2100.00');

    $changed = $keep;
    $changed['qty'] = '30';
    $changed['unit_cost'] = '90';
    $added = $this->f->line($this->f->juice, '12', '15');

    $response = $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$id}/lines", ['lines' => [$changed, $added]]);

    $response->assertOk()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('total', '2880.00')
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0.id', $keptRowId)
        ->assertJsonPath('lines.0.qty_base', '30000.0000')
        ->assertJsonPath('lines.0.unit_cost_base', '0.0900')
        ->assertJsonPath('lines.0.line_total', '2700.00')
        ->assertJsonPath('lines.1.client_uuid', $added['client_uuid'])
        ->assertJsonPath('lines.1.line_total', '180.00');

    expect(PurchaseLine::query()->withTrashed()->where('purchase_id', $id)->count())->toBe(3)
        ->and(PurchaseLine::query()->onlyTrashed()->where('client_uuid', $drop['client_uuid'])->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'purchase.lines_replace')->where('subject_id', $id)->exists())->toBeTrue()
        ->and(DB::table('stock_movements')->count())->toBe(0);

    // the same list again changes nothing visible; an empty list removes every line
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$id}/lines", ['lines' => [$changed, $added]])->assertOk()->assertJsonPath('total', '2880.00');
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$id}/lines", ['lines' => []])->assertOk()->assertJsonCount(0, 'lines')->assertJsonPath('total', '0.00');
})->group('module:inventory', 'feature');

it('refuses a line client_uuid that another purchase or a removed line already used, and a line moved to another purchase', function () {
    $mine = ($this->post)($this->f->body([$this->f->line($this->f->flour)]))->assertCreated();
    $line = $this->f->line($this->f->sugar);
    $other = ($this->post)($this->f->body([$line]))->assertCreated();

    $this->withHeaders(idempotencyKey())->putJson('/api/v1/purchases/'.$mine->json('id').'/lines', ['lines' => [$line]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.client_uuid']);
    ($this->post)($this->f->body([$line]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.client_uuid']);

    // a removed line's uuid stays taken
    $this->withHeaders(idempotencyKey())->putJson('/api/v1/purchases/'.$other->json('id').'/lines', ['lines' => []])->assertOk();
    $this->withHeaders(idempotencyKey())->putJson('/api/v1/purchases/'.$other->json('id').'/lines', ['lines' => [$line]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.client_uuid']);
})->group('module:inventory', 'feature');

it('replaces lines only on a draft, and only on a purchase of this branch', function () {
    $draft = ($this->post)($this->f->body([$this->f->line($this->f->flour)]))->assertCreated();
    $line = $this->f->line($this->f->sugar);

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases/'.$draft->json('id').'/confirm', [])->assertOk();
    $this->withHeaders(idempotencyKey())->putJson('/api/v1/purchases/'.$draft->json('id').'/lines', ['lines' => [$line]])
        ->assertStatus(409)->assertJsonPath('message_key', 'purchases.purchase.not_draft')->assertJsonPath('status', 'confirmed');

    $cancelled = ($this->post)($this->f->body([]))->assertCreated();
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases/'.$cancelled->json('id').'/cancel', [])->assertOk();
    $this->withHeaders(idempotencyKey())->putJson('/api/v1/purchases/'.$cancelled->json('id').'/lines', ['lines' => [$line]])
        ->assertStatus(409)->assertJsonPath('message_key', 'purchases.purchase.not_draft')->assertJsonPath('status', 'cancelled');

    $theirs = Purchase::factory()->create(['branch_id' => $this->other->id]);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$theirs->id}/lines", ['lines' => []])->assertNotFound();
})->group('module:inventory', 'feature');

it('validates the replacement lines with the rules of a new purchase', function () {
    $draft = ($this->post)($this->f->body([]))->assertCreated();
    $uri = '/api/v1/purchases/'.$draft->json('id').'/lines';

    $this->withHeaders(idempotencyKey())->putJson($uri, [])->assertStatus(422)->assertJsonValidationErrors(['lines']);
    $this->withHeaders(idempotencyKey())->putJson($uri, ['lines' => [['qty' => '1']]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.client_uuid', 'lines.0.item_id', 'lines.0.unit_id', 'lines.0.unit_cost']);

    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    $bad = $this->f->line($this->f->flour);
    $bad['item_id'] = $cake->id;
    $this->withHeaders(idempotencyKey())->putJson($uri, ['lines' => [$bad]])->assertStatus(422)->assertJsonPath('message_key', 'purchases.item.not_purchasable');
    $this->withHeaders(idempotencyKey())->putJson($uri, ['lines' => [$this->f->line($this->f->flour, '1', '10', catalogUnit('L'))]])->assertStatus(422)->assertJsonPath('message_key', 'purchases.unit.incompatible');
    $this->withHeaders(idempotencyKey())->putJson($uri, ['lines' => [$this->f->line($this->f->flour, '1', '0.0001')]])->assertStatus(422)->assertJsonPath('message_key', 'purchases.line.cost_too_small');
    expect(PurchaseLine::query()->withTrashed()->count())->toBe(0);
})->group('module:inventory', 'feature');

it('lists purchases without lines, filtered, newest first, paginated, of this branch only', function () {
    $mk = fn (array $over = []) => ($this->post)($this->f->body([$this->f->line($this->f->flour)], $over))->assertCreated()->json('id');
    $other = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $a = $mk(['purchase_date' => '2026-10-01']);
    $b = $mk(['purchase_date' => '2026-10-10', 'supplier_id' => $other->id]);
    $c = $mk(['purchase_date' => '2026-10-20']);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$c}/confirm", [])->assertOk();
    Purchase::factory()->create(['branch_id' => $this->other->id, 'purchase_date' => '2026-10-10']);

    $ids = fn (string $query = ''): array => array_column($this->getJson('/api/v1/purchases'.$query)->assertOk()->json('data'), 'id');

    expect($ids())->toBe([$c, $b, $a])
        ->and($ids('?status=draft'))->toBe([$b, $a])
        ->and($ids('?status=confirmed'))->toBe([$c])
        ->and($ids("?supplier_id={$other->id}"))->toBe([$b])
        ->and($ids('?from=2026-10-05&to=2026-10-15'))->toBe([$b])
        ->and($ids('?from=2026-10-10'))->toBe([$c, $b])
        ->and($ids('?to=2026-10-10'))->toBe([$b, $a]);

    $page = $this->getJson('/api/v1/purchases')->assertOk();
    expect($page->json('data.0'))->not->toHaveKey('lines')->and($page->json('meta.total'))->toBe(3)->and($page->json('meta.current_page'))->toBe(1);
    $this->getJson('/api/v1/purchases?status=bogus')->assertStatus(422)->assertJsonValidationErrors(['status']);
    $this->getJson('/api/v1/purchases?from=yesterday')->assertStatus(422)->assertJsonValidationErrors(['from']);
})->group('module:inventory', 'feature');

it('shows one purchase with its lines; another branch is 404', function () {
    $created = ($this->post)($this->f->body([$this->f->line($this->f->flour), $this->f->line($this->f->sugar)]))->assertCreated();

    $this->getJson('/api/v1/purchases/'.$created->json('id'))->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('id', $created->json('id'))->assertJsonPath('lines.1.item.id', $this->f->sugar->id);

    $theirs = Purchase::factory()->create(['branch_id' => $this->other->id]);
    $this->getJson("/api/v1/purchases/{$theirs->id}")->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
})->group('module:inventory', 'feature');

it('hides every cost field from a person who holds purchases.record without costs.view', function () {
    $created = ($this->post)($this->f->body([$this->f->line($this->f->flour)]))->assertCreated();

    $cashier = makeUser($this->branch, 'cashier');
    $cashier->givePermissionTo('purchases.record');
    loginAs($cashier);
    $view = $this->getJson('/api/v1/purchases/'.$created->json('id'))->assertOk();

    expect($view->json())->not->toHaveKey('total')
        ->and($view->json('lines.0'))->not->toHaveKeys(['unit_cost', 'unit_cost_base', 'line_total'])
        ->and($view->json('lines.0'))->toHaveKeys(['qty', 'qty_base', 'item', 'unit']);
    expect($this->getJson('/api/v1/purchases')->assertOk()->json('data.0'))->not->toHaveKey('total');

    // a manager keeps them
    loginAs($this->f->manager);
    expect($this->getJson('/api/v1/purchases/'.$created->json('id'))->assertOk()->json('total'))->toBe('1600.00');
})->group('module:inventory', 'feature');

it('refuses every role without purchases.record on every purchase route', function () {
    $created = ($this->post)($this->f->body([$this->f->line($this->f->flour)]))->assertCreated();
    $id = $created->json('id');

    foreach (['cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/purchases')->assertStatus(403);
        $this->getJson("/api/v1/purchases/{$id}")->assertStatus(403);
        ($this->post)($this->f->body([]))->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->putJson("/api/v1/purchases/{$id}/lines", ['lines' => []])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/confirm", [])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/cancel", [])->assertStatus(403);
    }
    loginAs($this->f->manager);
    $this->getJson("/api/v1/purchases/{$id}")->assertOk();
    expect(Purchase::query()->findOrFail($id)->status->value)->toBe('draft')->and(Purchase::query()->count())->toBe(1);
})->group('module:inventory', 'feature');
