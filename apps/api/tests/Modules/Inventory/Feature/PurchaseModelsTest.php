<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;

/*
 * The step 2.2a models and their factories: decimal casts (Quantity, UnitCost for rates, Money for totals), the status
 * enum, soft deletes where the plan says so, the branch scope (rule 8).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
});

it('builds a draft purchase in the branch, with typed money and a status enum', function () {
    $purchase = Purchase::factory()->create(['branch_id' => $this->branch->id])->fresh();

    expect($purchase->status)->toBe(PurchaseStatus::Draft)
        ->and($purchase->total)->toBeInstanceOf(Money::class)
        ->and($purchase->total->amount())->toBe('0.00')
        ->and($purchase->currency)->toBe('ETB')
        ->and($purchase->version)->toBe(1)
        ->and($purchase->confirmed_at)->toBeNull()
        ->and($purchase->supplier)->toBeInstanceOf(Supplier::class)
        ->and($purchase->location->branch_id)->toBe($this->branch->id)
        ->and($purchase->supplier->branch_id)->toBe($this->branch->id);
})->group('module:inventory', 'feature');

it('builds confirmed and cancelled purchases the CHECK constraints accept', function () {
    $confirmed = Purchase::factory()->confirmed()->create(['branch_id' => $this->branch->id])->fresh();
    $cancelled = Purchase::factory()->cancelled()->create(['branch_id' => $this->branch->id])->fresh();

    expect($confirmed->status)->toBe(PurchaseStatus::Confirmed)->and($confirmed->confirmed_at)->not->toBeNull()->and($confirmed->confirmed_by_user_id)->not->toBeNull()
        ->and($cancelled->status)->toBe(PurchaseStatus::Cancelled)->and($cancelled->cancelled_at)->not->toBeNull()->and($cancelled->confirmed_at)->toBeNull();
})->group('module:inventory', 'feature');

it('reads a purchase line as quantities, rates and money, and keeps its purchase', function () {
    $purchase = Purchase::factory()->create(['branch_id' => $this->branch->id]);
    $line = PurchaseLine::factory()->create(['branch_id' => $this->branch->id, 'purchase_id' => $purchase->id, 'qty_base' => '2000', 'unit_cost' => '85', 'unit_cost_base' => '0.085', 'line_total' => '170'])->fresh();

    expect($line->qty)->toBeInstanceOf(Quantity::class)
        ->and($line->qty_base->toString())->toBe('2000.0000')
        ->and($line->unit_cost)->toBeInstanceOf(UnitCost::class)
        ->and($line->unit_cost->toString())->toBe('85.0000')
        ->and($line->unit_cost_base->toString())->toBe('0.0850')
        ->and($line->line_total->amount())->toBe('170.00')
        ->and($purchase->lines()->count())->toBe(1);
})->group('module:inventory', 'feature');

it('soft-deletes a replaced purchase line and a supplier, and leaves them out of the purchase', function () {
    $purchase = Purchase::factory()->create(['branch_id' => $this->branch->id]);
    $line = PurchaseLine::factory()->create(['branch_id' => $this->branch->id, 'purchase_id' => $purchase->id]);
    $line->delete();
    $purchase->supplier->delete();

    expect($purchase->lines()->count())->toBe(0)
        ->and(PurchaseLine::withTrashed()->whereKey($line->id)->exists())->toBeTrue()
        ->and(Supplier::query()->count())->toBe(0)
        ->and(Supplier::withTrashed()->count())->toBe(1);
})->group('module:inventory', 'feature');

it('keeps a cost row and a history row as decimal strings behind typed values', function () {
    $cost = ItemCost::factory()->create(['branch_id' => $this->branch->id, 'moving_avg_cost' => '0.0883', 'qty_basis' => '7500'])->fresh();
    $history = ItemCostHistory::factory()->create(['branch_id' => $this->branch->id, 'item_id' => $cost->item_id, 'cost_before' => null])->fresh();

    expect($cost->moving_avg_cost)->toBeInstanceOf(UnitCost::class)
        ->and($cost->moving_avg_cost->toString())->toBe('0.0883')
        ->and($cost->qty_basis->toString())->toBe('7500.0000')
        ->and($cost->as_of)->not->toBeNull()
        ->and($cost->item->id)->toBe($cost->item_id)
        ->and($history->cost_before)->toBeNull()
        ->and($history->cost_after)->toBeInstanceOf(UnitCost::class)
        ->and($history->qty_in->toString())->toBe('10.0000');
})->group('module:inventory', 'feature');

it('shows a branch only its own purchases, suppliers and costs', function () {
    Purchase::factory()->create(['branch_id' => $this->branch->id]);
    $other = Branch::factory()->create();
    Purchase::factory()->create(['branch_id' => $other->id]);
    ItemCost::factory()->create(['branch_id' => $other->id]);

    expect(Purchase::query()->count())->toBe(1)->and(Supplier::query()->count())->toBe(1)->and(ItemCost::query()->count())->toBe(0);
})->group('module:inventory', 'feature');
