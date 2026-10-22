<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Supplier;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TransferFixture;

/*
 * Phase 2 gate, the Pest path of the p2-purchase-transfer flow (docs/phases/phase-2-plan.md section 7): the admin records and
 * confirms a purchase -> the store balance rises and the moving average equals the typed cost to 4 dp -> a second purchase at
 * another cost moves the average to the value computed here with an independent decimal helper -> the chef asks for 5 kg for the
 * kitchen -> the manager confirms -> store -5 kg, kitchen +5 kg, two `transfer` ledger rows with one ref -> a second request is
 * rejected with a reason and nothing moves.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = TransferFixture::build($this->branch);
    $this->supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Addis Mill']);
    $this->buy = function (string $qty, string $cost): int {
        loginAs($this->f->admin);
        $id = (int) $this->withHeaders(idempotencyKey())->postJson('/api/v1/purchases', [
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id, 'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'purchase_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [['client_uuid' => (string) Str::uuid(), 'item_id' => $this->f->flour->id, 'qty' => $qty, 'unit_id' => $this->f->flour->unit_id, 'unit_cost' => $cost]],
        ])->assertCreated()->json('id');
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/purchases/{$id}/confirm", [])->assertOk()->assertJsonPath('status', 'confirmed');

        return $id;
    };
    $this->average = fn (): string => (string) DB::table('item_costs')->where('item_id', $this->f->flour->id)->value('moving_avg_cost');
    $this->onHand = fn ($at): string => (string) stockRow($this->f->flour, $at)?->on_hand;
});

it('purchases raise the store balance and the moving average to 4 dp; a transfer is confirmed or rejected and only a confirm moves stock', function () {
    // 20 kg at 80.00 a kg: 20 000 g at 0.0800 a gram
    ($this->buy)('20', '80');
    expect(($this->onHand)($this->f->store))->toBe('20000.0000')->and(($this->average)())->toBe('0.0800');

    // 10 kg at 95.00: (20 000 x 0.0800 + 10 000 x 0.0950) / 30 000, computed here in exact decimals and rounded half-up once
    ($this->buy)('10', '95');
    $expected = BigDecimal::of('20000')->multipliedBy('0.0800')->plus(BigDecimal::of('10000')->multipliedBy('0.0950'))->dividedBy('30000', 4, RoundingMode::HalfUp)->__toString();
    expect($expected)->toBe('0.0850')->and(($this->average)())->toBe($expected)->and(($this->onHand)($this->f->store))->toBe('30000.0000');

    // the chef asks for 5 kg for the kitchen; the manager confirms
    loginAs($this->f->chef);
    $ask = fn () => $this->withHeaders(idempotencyKey())->postJson('/api/v1/transfers', $this->f->body($this->f->flour, $this->f->kitchen, '5000'))->assertCreated()->assertJsonPath('status', 'requested');
    $first = $ask()->json('id');
    expect(($this->onHand)($this->f->store))->toBe('30000.0000', 'a request moves nothing');

    loginAs($this->f->manager);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/transfers/{$first}/confirm", [])->assertOk()->assertJsonPath('status', 'confirmed');
    $rows = DB::table('stock_movements')->where('ref_type', 'transfer')->where('ref_id', $first)->orderBy('id')->get();
    expect(($this->onHand)($this->f->store))->toBe('25000.0000')->and(($this->onHand)($this->f->kitchen))->toBe('5000.0000')
        ->and($rows)->toHaveCount(2)->and($rows->pluck('qty')->all())->toBe(['-5000.0000', '5000.0000'])
        ->and(($this->average)())->toBe('0.0850', 'a transfer does not change what the stock cost');

    // a second request is rejected with a reason and nothing moves
    loginAs($this->f->chef);
    $second = $ask()->json('id');
    loginAs($this->f->manager);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/transfers/{$second}/reject", ['reason' => 'Kept for the wedding order'])->assertOk()->assertJsonPath('status', 'rejected');
    expect(($this->onHand)($this->f->store))->toBe('25000.0000')->and(($this->onHand)($this->f->kitchen))->toBe('5000.0000')
        ->and(DB::table('stock_movements')->where('ref_type', 'transfer')->where('ref_id', $second)->count())->toBe(0);
})->group('gate:p2', 'feature', 'module:inventory');
