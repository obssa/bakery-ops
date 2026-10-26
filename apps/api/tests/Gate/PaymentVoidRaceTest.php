<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Shared\Values\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\OrderFloor;

/*
 * A void racing the last payment of the same bill (phase-4 plan section 7, PaymentVoidRaceTest; step 4.3c). A served order of 30.00 has a part
 * payment of 10.00; at the same moment one till voids that part payment (with the manager's PIN) and another takes the 20.00 left. Each is a real
 * request through the whole HTTP stack in its own forked process. Both lock the order row first (Orders::forPayment, FOR UPDATE), so they run one
 * after the other and the second sees what the first did:
 *   - the void goes first: the order is back to 30.00 to pay, and the 20.00 is taken as a part payment (the order stays open);
 *   - the payment goes first: the bill is paid, the order closes, and the void is refused (409 payments.void.order_closed: refund it instead).
 * Either way paid_total equals the sum of the order's payments that are not voided, the order is never closed with a voided payment in its
 * total, the water is sold at most once, and the engine detects no deadlock (Innodb_deadlocks, 30 rounds).
 * CommitsForConcurrency: the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('paymentVoidRaceDeadlocks')) {
    function paymentVoidRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    $this->f = OrderFloor::open($this);
    $this->f->assign($this->f->manager, $this->f->menu->tillStation); // the second till is the manager's, in the same till shift
    withActor($this->f->admin->id);
    app(ShortCodes::class)->set($this->f->admin, '864209');
    $this->post = fn (User $as, string $uri, array $body): Closure => function () use ($as, $uri, $body): array {
        loginAs($as);
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson($uri, $body);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key')];
    };
});

it('serialises a void and the last payment of one bill on the order lock: one of the two valid outcomes, never a wrong total (30 rounds)', function () {
    $f = $this->f;
    $deadlocksBefore = paymentVoidRaceDeadlocks();
    $failures = [];
    $seen = [];

    foreach (range(1, 30) as $round) {
        stockIn($f->menu->water, $f->menu->display, '1');
        $order = $f->send([$f->menu->line($f->menu->water)]); // 30.00
        $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();
        $part = ($this->post)($f->cashier, "/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '10.00'])();
        $partId = (int) Payment::query()->where('order_id', $order['id'])->value('id');
        if ($part['status'] !== 201 || $partId === 0) {
            $failures[] = "round {$round}: the part payment was not taken: ".json_encode($part);

            continue;
        }
        $sales = DB::table('stock_movements')->where('reason', 'sale')->count();

        $results = Forks::together([
            ($this->post)($f->cashier, "/api/v1/payments/{$partId}/void", ['reason' => 'Keyed on the wrong bill', 'pin' => '864209']),
            ($this->post)($f->manager, "/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '20.00']),
        ]);

        $outcomes = array_map(fn (array $r): string => ($r['ok'] ?? false) ? $r['value']['status'].' '.$r['value']['message_key'] : 'error '.($r['error'] ?? '?'), $results);
        $row = Order::query()->findOrFail($order['id']);
        $payments = Payment::query()->where('order_id', $order['id'])->get();
        $counted = $payments->filter(fn (Payment $p): bool => $p->status->value !== 'voided')
            ->reduce(fn (Money $sum, Payment $p): Money => $sum->plus($p->amount), Money::zero());
        $sold = DB::table('stock_movements')->where('reason', 'sale')->count() - $sales;

        $voidFirst = $outcomes === ['200 ', '201 '] && $row->status->value === 'served' && $row->paid_total->amount() === '20.00' && $row->settled_at === null && $sold === 0;
        $payFirst = $outcomes === ['409 payments.void.order_closed', '201 '] && $row->status->value === 'closed' && $row->paid_total->amount() === '30.00' && $sold === 1;
        if (! $voidFirst && ! $payFirst) {
            $failures[] = "round {$round}: ".json_encode($outcomes)." status {$row->status->value} paid {$row->paid_total->amount()} sold {$sold}";
        }
        if (! $row->paid_total->equals($counted) || $payments->count() !== 2) {
            $failures[] = "round {$round}: paid_total {$row->paid_total->amount()} but the payments that count add up to {$counted->amount()}";
        }
        $seen[$voidFirst ? 'void first' : ($payFirst ? 'pay first' : 'other')] = true;
    }

    expect($failures)->toBe([])->and(paymentVoidRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    fwrite(STDERR, 'PaymentVoidRaceTest outcomes seen: '.implode(', ', array_keys($seen)).PHP_EOL);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:payments', 'feature');
