<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Orders\Contracts\LineFact;
use App\Modules\Orders\Contracts\SaleFact;
use App\Modules\Orders\Contracts\VoidFact;
use App\Modules\Payments\Contracts\RefundFact;
use Brick\Math\RoundingMode;

/**
 * daily_sales (phase-4 plan M13; ADR 0014): ONE row per built day, zeros on a quiet day. Each figure on its own clock, as the ports
 * deliver them: sales and their lines by settled_at, voids by voided_at, cancellations by cancelled_at, refunds by refunded_at (cash
 * basis, so net revenue can be below zero). A refund paid back over several payments is one refund (its order_refund_id). Pure.
 */
final class SalesBuilder
{
    private const array TYPES = ['dine_in', 'takeaway', 'delivery', 'advance'];

    /**
     * @param  list<SaleFact>  $sales
     * @param  list<LineFact>  $lines
     * @param  list<VoidFact>  $voids
     * @param  list<RefundFact>  $refunds
     * @return list<array<string, mixed>>
     */
    public function build(string $day, array $sales, array $lines, array $voids, array $refunds): array
    {
        $revenue = Figures::sum(array_map(static fn (SaleFact $s): string => $s->total, $sales));
        $count = count($sales);
        $voided = array_values(array_filter($voids, static fn (VoidFact $v): bool => $v->status === 'voided'));
        $refundTotal = Figures::sum(array_map(static fn (RefundFact $r): string => $r->amount, $refunds));
        $byType = [];
        foreach (self::TYPES as $type) {
            $byType[$type.'_revenue'] = Figures::money(Figures::sum(array_map(
                static fn (SaleFact $s): string => $s->total,
                array_filter($sales, static fn (SaleFact $s): bool => $s->type === $type),
            )));
        }

        return [[
            'day' => $day,
            'orders_count' => $count,
            'items_qty' => Figures::qty(Figures::sum(array_map(static fn (LineFact $l): string => $l->qty, $lines))),
            'subtotal' => Figures::money(Figures::sum(array_map(static fn (SaleFact $s): string => $s->subtotal, $sales))),
            'discount_total' => Figures::money(Figures::sum(array_map(static fn (SaleFact $s): string => $s->discount, $sales))),
            'tax_total' => Figures::money(Figures::sum(array_map(static fn (SaleFact $s): string => $s->tax, $sales))),
            'revenue' => Figures::money($revenue),
            'avg_ticket' => $count === 0 ? '0.00' : (string) $revenue->dividedBy($count, 2, RoundingMode::HalfUp),
            'voided_count' => count($voided),
            'voided_total' => Figures::money(Figures::sum(array_map(static fn (VoidFact $v): string => $v->total, $voided))),
            'cancelled_count' => count(array_filter($voids, static fn (VoidFact $v): bool => $v->status === 'cancelled')),
            'refund_count' => count(array_unique(array_map(static fn (RefundFact $r): int => $r->orderRefundId, $refunds))),
            'refund_total' => Figures::money($refundTotal),
            'net_revenue' => Figures::money($revenue->minus($refundTotal)),
        ] + $byType];
    }
}
