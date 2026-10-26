<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Orders\Contracts\LineFact;
use App\Modules\Orders\Contracts\SaleFact;
use App\Modules\Orders\Contracts\StationLineFact;
use App\Modules\Orders\Contracts\VoidFact;
use App\Modules\Payments\Contracts\PaymentFact;
use App\Modules\Payments\Contracts\RefundFact;
use Brick\Math\BigDecimal;

/**
 * daily_user_metrics (phase-4 plan M16; ADR 0014). One row per person and role kind, the role kind being what the person did that day,
 * not the role they hold:
 *   waiter   the orders they own settled that day (orders, items of their lines, revenue) and the voids they asked for that day;
 *   cashier  the payments they took that day and the refunds they paid back (a refund over several payments is one refund);
 *   station  the station lines they readied that day, and the mean prep time of those accepted first (with, step 4.4c, the number
 *            of those samples and their seconds added, for an exact mean over a range).
 * A person with nothing in a kind has no row of that kind; rows ascend by (user, role kind). Pure.
 */
final class UserBuilder
{
    /**
     * @param  list<SaleFact>  $sales
     * @param  list<LineFact>  $lines
     * @param  list<VoidFact>  $voids
     * @param  list<PaymentFact>  $payments
     * @param  list<RefundFact>  $refunds
     * @param  list<StationLineFact>  $stationLines
     * @return list<array<string, mixed>>
     */
    public function build(string $day, string $timezone, array $sales, array $lines, array $voids, array $payments, array $refunds, array $stationLines): array
    {
        /** @var array<int, array<string, array{orders: int, items: BigDecimal, revenue: BigDecimal, payments: int, paid: BigDecimal, voids: int, refunds: array<int, true>, prepared: int, prep: list<int>}>> $p */
        $p = [];
        $blank = static fn (): array => [
            'orders' => 0, 'items' => BigDecimal::zero(), 'revenue' => BigDecimal::zero(), 'payments' => 0, 'paid' => BigDecimal::zero(),
            'voids' => 0, 'refunds' => [], 'prepared' => 0, 'prep' => [],
        ];
        $ownerOf = [];
        foreach ($sales as $sale) {
            if ($sale->ownerUserId === null) {
                continue;
            }
            $ownerOf[$sale->orderId] = $sale->ownerUserId;
            $c = $p[$sale->ownerUserId]['waiter'] ?? $blank();
            $c['orders']++;
            $c['revenue'] = $c['revenue']->plus($sale->total);
            $p[$sale->ownerUserId]['waiter'] = $c;
        }
        foreach ($lines as $line) {
            $owner = $ownerOf[$line->orderId] ?? null;
            if ($owner !== null) {
                $c = $p[$owner]['waiter'] ?? $blank();
                $c['items'] = $c['items']->plus($line->qty);
                $p[$owner]['waiter'] = $c;
            }
        }
        foreach ($voids as $void) {
            if ($void->status === 'voided' && $void->voidedByUserId !== null) {
                $c = $p[$void->voidedByUserId]['waiter'] ?? $blank();
                $c['voids']++;
                $p[$void->voidedByUserId]['waiter'] = $c;
            }
        }
        foreach ($payments as $payment) {
            $c = $p[$payment->receivedByUserId]['cashier'] ?? $blank();
            $c['payments']++;
            $c['paid'] = $c['paid']->plus($payment->amount);
            $p[$payment->receivedByUserId]['cashier'] = $c;
        }
        foreach ($refunds as $refund) {
            $c = $p[$refund->refundedByUserId]['cashier'] ?? $blank();
            $c['refunds'][$refund->orderRefundId] = true;
            $p[$refund->refundedByUserId]['cashier'] = $c;
        }
        foreach ($stationLines as $line) {
            if ($line->preparedByUserId === null || $line->readyAt === null || Figures::dayOf($line->readyAt, $timezone) !== $day) {
                continue;
            }
            $c = $p[$line->preparedByUserId]['station'] ?? $blank();
            $c['prepared']++;
            if ($line->acceptedAt !== null) {
                $c['prep'][] = Figures::seconds($line->acceptedAt, $line->readyAt);
            }
            $p[$line->preparedByUserId]['station'] = $c;
        }
        ksort($p);

        $rows = [];
        foreach ($p as $userId => $kinds) {
            ksort($kinds);
            foreach ($kinds as $kind => $c) {
                $rows[] = [
                    'day' => $day,
                    'user_id' => $userId,
                    'role_kind' => $kind,
                    'orders_count' => $c['orders'],
                    'items_qty' => Figures::qty($c['items']),
                    'revenue' => Figures::money($c['revenue']),
                    'payments_count' => $c['payments'],
                    'payments_amount' => Figures::money($c['paid']),
                    'voids_count' => $c['voids'],
                    'refunds_count' => count($c['refunds']),
                    'lines_prepared' => $c['prepared'],
                    'avg_prep_seconds' => Figures::mean($c['prep']),
                    'prep_samples' => count($c['prep']),
                    'prep_seconds_sum' => array_sum($c['prep']),
                ];
            }
        }

        return $rows;
    }
}
