<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Exceptions\PurchaseError;
use App\Shared\Values\Money;
use App\Shared\Values\NumericOverflow;
use Illuminate\Validation\ValidationException;

/**
 * Turns the lines a client typed into lines the ledger can receive (phase-2 plan sections 3 and 10.3), before anything is
 * written, so a refused line leaves nothing behind:
 *   - the item is a live, active item of this branch that is bought: an ingredient, or a bought-in product;
 *   - the unit converts to the item's base unit (Units::convertible); the quantity becomes base units (Units::toBase) and
 *     the typed cost per unit becomes a cost per base unit (UnitCost::dividedBy the unit's factor, half-up to 4 dp);
 *   - a typed cost that is not zero but rounds to 0.0000 per base unit is refused (cost_too_small) rather than recorded as free;
 *   - the line total is the typed quantity x the typed cost, half-up to 2 dp, in the branch's currency.
 * A value that does not fit its column is a 422 on the field (validation.failed), never a database error.
 * Resolved per use (it reads the branch-scoped Item model).
 */
final class LineResolver
{
    public function __construct(private readonly Units $units) {}

    /**
     * @param  list<LineInput>  $inputs
     * @return list<PricedLine>
     *
     * @throws PurchaseError purchases.item.not_purchasable, purchases.unit.incompatible, purchases.line.cost_too_small (422)
     * @throws ValidationException a quantity, cost or total too large for its column
     */
    public function resolve(array $inputs, string $currency): array
    {
        $items = Item::query()->whereKey(array_values(array_unique(array_map(static fn (LineInput $l): int => $l->itemId, $inputs))))->get()->keyBy('id');

        $priced = [];
        foreach ($inputs as $input) {
            $item = $items->get($input->itemId);
            if (! $item instanceof Item || ! self::isPurchasable($item)) {
                throw PurchaseError::itemNotPurchasable($input->index, $input->itemId);
            }
            if (! $this->units->convertible($input->unitId, $item->unit_id)) {
                throw PurchaseError::unitIncompatible($input->index);
            }

            try {
                $qtyBase = $this->units->toBase($input->qty, $input->unitId);
            } catch (NumericOverflow) {
                throw self::tooLarge("lines.{$input->index}.qty");
            }
            $unitCostBase = $input->unitCost->dividedBy($this->units->resolve($input->unitId)->factor);
            if ($unitCostBase->isZero() && ! $input->unitCost->isZero()) {
                throw PurchaseError::costTooSmall($input->index);
            }
            try {
                $lineTotal = $input->unitCost->times($input->qty, $currency);
            } catch (NumericOverflow) {
                throw self::tooLarge("lines.{$input->index}.unit_cost");
            }

            $priced[] = new PricedLine($input, $qtyBase, $unitCostBase, $lineTotal);
        }

        return $priced;
    }

    /**
     * The purchase total: the sum of the line totals, which must fit NUMERIC(12,2).
     *
     * @param  list<PricedLine>  $lines
     *
     * @throws ValidationException
     */
    public static function total(array $lines, string $currency): Money
    {
        $total = Money::zero($currency);
        try {
            foreach ($lines as $line) {
                $total = $total->plus($line->lineTotal);
            }
        } catch (NumericOverflow) {
            throw self::tooLarge('lines');
        }

        return $total;
    }

    /** Ingredients, and products that are bought in (water, juice); a cake or a sandwich is made, not bought. */
    public static function isPurchasable(Item $item): bool
    {
        return $item->is_active && ($item->kind === ItemKind::Ingredient || $item->fulfilment === Fulfilment::BoughtIn);
    }

    private static function tooLarge(string $field): ValidationException
    {
        $message = trans('purchases.validation.too_large');

        return ValidationException::withMessages([$field => [is_string($message) ? $message : 'This value is too large.']]);
    }
}
