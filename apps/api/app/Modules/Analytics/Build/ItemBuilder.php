<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Catalog\Contracts\ItemSummary;
use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Inventory\Contracts\ClosingBalanceFact;
use App\Modules\Inventory\Contracts\MovementFact;
use App\Modules\Inventory\Contracts\StockOutFact;
use App\Modules\Inventory\Contracts\UnitCostFact;
use App\Modules\Orders\Contracts\LineFact;
use App\Modules\Orders\Contracts\RefundLineFact;
use Brick\Math\BigDecimal;

/**
 * daily_item_metrics (phase-4 plan M15; ADR 0014; 10.2 R-K). Per item, each figure on its own clock: units sold, revenue and COGS from
 * the lines of the day's sales (settled_at); produced (production_output), consumed (production_consume), waste, purchased
 * (purchase_in) and adjusted (count_adjustment, signed) from the day's movements (created_at); transfers, reservations, sales and
 * returns of the ledger are not item figures; stock-outs begun that day; the closing on-hand over every place at the end of the day,
 * valued at the day's cost (0 for an item with no cost). COGS through CostOfSales, rounded half-up once per item-day. refunded_qty is
 * the quantity of the item's order lines refunded that day, on the refund's own clock (Orders\Facts::refundLines, step 4.3d), restocked
 * or wasted alike; revenue and units sold stay on the day of the sale. An item with nothing to say that day has no row; rows ascend by
 * item id. Pure.
 */
final class ItemBuilder
{
    private const array MOVES = [
        'production_output' => 'produced',
        'production_consume' => 'consumed',
        'waste' => 'waste',
        'purchase_in' => 'purchased',
        'count_adjustment' => 'adjusted',
    ];

    /** Figures that are reported as a positive quantity although the ledger row takes stock away. */
    private const array OUTGOING = ['consumed', 'waste'];

    public function __construct(private readonly CostOfSales $cost = new CostOfSales) {}

    /**
     * @param  list<LineFact>  $lines
     * @param  list<MovementFact>  $movements
     * @param  list<ClosingBalanceFact>  $closings
     * @param  list<StockOutFact>  $stockOuts
     * @param  list<UnitCostFact>  $unitCosts
     * @param  array<int, ItemSummary>  $items  item id => what Catalog says of it
     * @param  array<string, RecipeView|null>  $recipes  "product id:version" => the version the lines snapshotted
     * @param  list<RefundLineFact>  $refundLines  the lines refunded that day (step 4.3d)
     * @return list<array<string, mixed>>
     */
    public function build(string $day, array $lines, array $movements, array $closings, array $stockOuts, array $unitCosts, array $items, array $recipes, array $refundLines = []): array
    {
        $costs = [];
        foreach ($unitCosts as $cost) {
            $costs[$cost->itemId] = $cost->unitCost;
        }
        $sold = [];
        foreach ($lines as $line) {
            $sold[$line->itemId][] = $line;
        }
        $moved = [];
        foreach ($movements as $movement) {
            $figure = self::MOVES[$movement->reason] ?? null;
            if ($figure !== null) {
                $moved[$movement->itemId][$figure] = ($moved[$movement->itemId][$figure] ?? BigDecimal::zero())->plus($movement->qty);
            }
        }
        $outs = [];
        foreach ($stockOuts as $out) {
            $outs[$out->itemId] = ($outs[$out->itemId] ?? 0) + 1;
        }
        $refunded = [];
        foreach ($refundLines as $refund) {
            $refunded[$refund->itemId] = ($refunded[$refund->itemId] ?? BigDecimal::zero())->plus($refund->qty);
        }
        $closing = [];
        foreach ($closings as $balance) {
            $closing[$balance->itemId] = ($closing[$balance->itemId] ?? BigDecimal::zero())->plus($balance->onHand);
        }

        $ids = array_values(array_unique(array_merge(array_keys($sold), array_keys($moved), array_keys($outs), array_keys($closing), array_keys($refunded))));
        sort($ids);

        $rows = [];
        foreach ($ids as $itemId) {
            $itemLines = $sold[$itemId] ?? [];
            $figures = [];
            foreach (self::MOVES as $figure) {
                $sum = $moved[$itemId][$figure] ?? BigDecimal::zero();
                $figures[$figure] = in_array($figure, self::OUTGOING, true) ? $sum->negated() : $sum;
            }
            $units = Figures::sum(array_map(static fn (LineFact $l): string => $l->qty, $itemLines));
            $revenue = Figures::sum(array_map(static fn (LineFact $l): string => $l->lineTotal, $itemLines));
            [$cogsExact, $complete] = $this->cost->of($itemLines, $recipes, $costs);
            $cogs = BigDecimal::of(Figures::money($cogsExact));
            $onHand = $closing[$itemId] ?? BigDecimal::zero();
            $value = isset($costs[$itemId]) ? $onHand->multipliedBy($costs[$itemId]) : BigDecimal::zero();
            $stockouts = $outs[$itemId] ?? 0;
            $back = $refunded[$itemId] ?? BigDecimal::zero();

            $nothing = $units->isZero() && $revenue->isZero() && $onHand->isZero() && $stockouts === 0 && $back->isZero()
                && array_filter($figures, static fn (BigDecimal $f): bool => ! $f->isZero()) === [];
            if ($nothing) {
                continue;
            }

            $summary = $items[$itemId] ?? null;
            $rows[] = [
                'day' => $day,
                'item_id' => $itemId,
                'category_id' => $summary?->categoryId,
                'kind' => $summary !== null ? $summary->kind : ($itemLines === [] ? 'ingredient' : 'product'),
                'units_sold_qty' => Figures::qty($units),
                'refunded_qty' => Figures::qty($back),
                'produced_qty' => Figures::qty($figures['produced']),
                'consumed_qty' => Figures::qty($figures['consumed']),
                'waste_qty' => Figures::qty($figures['waste']),
                'purchased_qty' => Figures::qty($figures['purchased']),
                'adjusted_qty' => Figures::qty($figures['adjusted']),
                'stockout_count' => $stockouts,
                'closing_on_hand_qty' => Figures::qty($onHand),
                'revenue' => Figures::money($revenue),
                'cogs_total' => Figures::money($cogs),
                'margin_total' => Figures::money($revenue->minus($cogs)),
                'closing_value_total' => Figures::money($value),
                'cogs_complete' => $complete,
            ];
        }

        return $rows;
    }
}
