<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Models\Order;
use App\Shared\Events\PaymentRecorded;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\OrderFloor;

/*
 * Contract: payments -> orders, case 12 of the phase-4 plan section 2 (step 4.1a): two tills pay the LAST balance of one order at the
 * same moment. What Payments does, in its own transaction: ask `Orders::forPayment($id, lock: true)` (the order's row lock), refuse when the
 * amount is more than the balance it saw (`422 payments.amount.mismatch`), else dispatch PaymentRecorded. So exactly one of the two is
 * recorded, the other sees a zero balance after the first commits, and the order's paid_total is the total, never more. The lock is
 * Orders' promise; it exists since step 1.4, so this case passes now and pins it for the split payments of Phase 4.
 * Two real processes (pcntl_fork), CommitsForConcurrency (DatabaseTruncation): the children see only committed rows.
 * Group `contract:payments->orders:refund` like the other Phase 4 cases (no CI job runs that name before step 4.1b).
 */

uses(CommitsForConcurrency::class);

it('lets exactly one of two payments of the last balance through; the other is told the amount no longer matches', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    for ($round = 1; $round <= 5; $round++) {
        stockIn($m->croissant, $m->display, '5'); // the display case has enough for every round
        $order = $f->send([$m->line($m->croissant)]);
        $total = app(Orders::class)->totals($order['id'])->total;

        $till = function (int $paymentId) use ($f, $order, $total): string {
            withActor($f->cashier->id);
            DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');

            return DB::transaction(function () use ($f, $order, $total, $paymentId): string {
                $view = app(Orders::class)->forPayment($order['id'], true);
                if ($view->totals->balance !== $total) {
                    return 'payments.amount.mismatch';
                }
                event(new PaymentRecorded($f->branch->id, $paymentId, $order['id'], 'cash', $total, '0.00'));

                return 'recorded';
            });
        };

        $results = Forks::together([fn () => $till(1), fn () => $till(2)]);

        $answers = [];
        foreach ($results as $result) {
            expect($result['ok'])->toBeTrue($result['error'] ?? '');
            $answers[] = $result['value'];
        }
        sort($answers);
        expect($answers)->toBe(['payments.amount.mismatch', 'recorded'], "round {$round}")
            ->and(Order::query()->findOrFail($order['id'])->paid_total->amount())->toBe($total, "round {$round}");
    }
})->group('contract:payments->orders:refund', 'contract', 'concurrency');
