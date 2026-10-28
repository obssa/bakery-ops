<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'property', 'feature');

/*
 * RefundMoneyPropertyTest (phase-4 plan section 7, step 4.3d): random bills paid in one part or split over cash and Telebirr, some with a part
 * payment voided first, then refunded whole, by line, or twice, all through the HTTP endpoints. After every sequence the money adds up:
 *   Σ payments − Σ voided payments − Σ payment refunds = Σ order totals − Σ refunded_total
 * and, row by row, each payment's refunded_amount is the sum of its payment_refunds rows and never more than it took, each order's
 * refunded_total is the sum of its refunds' amounts and of their money rows; the ledger oracle holds (movements, balances, the view).
 * The seed is printed; REFUND_PROPERTY_SEED=<n> replays one.
 */

it('keeps payments, voids and refunds equal to the orders\' totals less what was refunded, over random sequences, and the ledger oracle holds', function () {
    Storage::fake(config('files.disk'));
    $seed = (int) (getenv('REFUND_PROPERTY_SEED') ?: random_int(1, PHP_INT_MAX >> 1));
    fwrite(STDERR, "RefundMoneyPropertyTest seed {$seed} (REFUND_PROPERTY_SEED=<n> replays one)\n");
    $random = new Randomizer(new Mt19937($seed));
    $f = OrderFloor::open($this);
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    stockIn($m->croissant, $m->display, '60');
    stockIn($m->water, $m->display, '60');
    $post = function (string $uri, array $body) use ($f): TestResponse {
        loginAs($f->cashier);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson($uri, $body);
    };
    $pay = fn (array $order, string $channel, string $amount): TestResponse => $post("/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => $channel, 'amount' => $amount]
        + ($channel === 'cash' ? [] : ['reference_no' => 'FT'.$random->getInt(10000000, 99999999), 'receipt_file_id' => ReceiptFiles::upload($this, $f->cashier)]));
    $refunds = 0;
    $voids = 0;

    foreach (range(1, 8) as $round) {
        $lines = [];
        foreach (range(1, $random->getInt(1, 3)) as $n) {
            $lines[] = $m->line($random->getInt(0, 1) === 0 ? $m->croissant : $m->water, (string) $random->getInt(1, 2));
        }
        $order = $f->send($lines);
        foreach ($order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
        $total = BigDecimal::of($order['totals']['total']);
        if ($random->getInt(0, 2) === 0) {
            $part = (int) $pay($order, 'cash', '10.00')->assertCreated()->json('payment.id');
            $post("/api/v1/payments/{$part}/void", ['reason' => 'Keyed on the wrong bill', 'pin' => '246802'])->assertOk();
            $voids++;
        }
        if ($random->getInt(0, 1) === 0) {
            $pay($order, $random->getInt(0, 1) === 0 ? 'cash' : 'telebirr', (string) $total->toScale(2))->assertCreated();
        } else {
            $first = BigDecimal::of($random->getInt(1, (int) $total->toInt() - 1))->toScale(2);
            $pay($order, 'telebirr', (string) $first)->assertCreated();
            $pay($order, 'cash', (string) $total->minus($first)->toScale(2))->assertCreated();
        }

        $lineIds = array_map(fn (array $l): int => $l['id'], $order['lines']);
        $refund = fn (array $named): TestResponse => $post("/api/v1/orders/{$order['id']}/refund", [
            'client_uuid' => (string) Str::uuid(), 'reason' => 'Returned', 'pin' => '246802', 'lines' => $named, 'refund_reference' => 'FT'.$random->getInt(10000000, 99999999),
        ]);
        switch ($random->getInt(0, 3)) {
            case 0:
                break; // kept
            case 1:
                $refund([])->assertCreated();
                $refunds++;
                break;
            default:
                $lineIds = $random->shuffleArray($lineIds);
                $refund([$lineIds[0]])->assertCreated();
                $refunds++;
                if (count($lineIds) > 1 && $random->getInt(0, 1) === 1) {
                    $refund(array_slice($lineIds, 1))->assertCreated()->assertJsonPath('refund.is_full', true);
                    $refunds++;
                }
        }
    }

    $sum = fn (iterable $amounts): BigDecimal => array_reduce([...$amounts], fn (BigDecimal $s, mixed $a): BigDecimal => $s->plus((string) $a), BigDecimal::zero());
    $b = $f->branch->id;
    $taken = $sum(DB::table('payments')->where('branch_id', $b)->pluck('amount'));
    $voided = $sum(DB::table('payments')->where('branch_id', $b)->where('status', 'voided')->pluck('amount'));
    $back = $sum(DB::table('payment_refunds')->where('branch_id', $b)->pluck('amount'));
    $orders = DB::table('orders')->where('branch_id', $b)->get();
    expect($orders->every(fn (object $o): bool => $o->status === 'closed'))->toBeTrue("seed {$seed}: every bill closed")
        ->and((string) $taken->minus($voided)->minus($back)->toScale(2))
        ->toBe((string) $sum($orders->pluck('total'))->minus($sum($orders->pluck('refunded_total')))->toScale(2), "seed {$seed}: payments - voids - refunds = totals - refunded")
        ->and(DB::table('order_refunds')->where('branch_id', $b)->count())->toBe($refunds, "seed {$seed}")
        ->and(DB::table('payments')->where('branch_id', $b)->where('status', 'voided')->count())->toBe($voids, "seed {$seed}");
    foreach (DB::table('payments')->where('branch_id', $b)->get() as $payment) {
        $rows = $sum(DB::table('payment_refunds')->where('payment_id', $payment->id)->pluck('amount'));
        expect((string) $rows->toScale(2))->toBe((string) $payment->refunded_amount, "seed {$seed}: payment {$payment->id} refunded_amount")
            ->and(BigDecimal::of((string) $payment->refunded_amount)->isLessThanOrEqualTo((string) $payment->amount))->toBeTrue("seed {$seed}: payment {$payment->id} gave back more than it took")
            ->and($payment->status === 'voided' && ! $rows->isZero())->toBeFalse("seed {$seed}: a voided payment gave money back");
    }
    foreach ($orders as $order) {
        $refunded = (string) $order->refunded_total;
        expect((string) $sum(DB::table('order_refunds')->where('order_id', $order->id)->pluck('amount'))->toScale(2))->toBe($refunded, "seed {$seed}: order {$order->id}")
            ->and((string) $sum(DB::table('payment_refunds')->where('order_id', $order->id)->pluck('amount'))->toScale(2))->toBe($refunded, "seed {$seed}: order {$order->id} money rows");
    }
    $oracle = ledgerOracle($b);
    expect($oracle['movements'])->toBe($oracle['balances'], "seed {$seed}")->and($oracle['balances'])->toBe($oracle['view'], "seed {$seed}");
});
