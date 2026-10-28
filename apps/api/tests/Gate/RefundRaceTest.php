<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\OrderFloor;

/*
 * Two managers refund the same order at the same moment (phase-4 plan section 7, RefundRaceTest; step 4.3d). A closed order of a croissant and a
 * water (90.00, paid in cash) is refunded whole by two managers at the two tills of one shift, each with the admin's PIN, each a real request
 * through the whole HTTP stack in its own forked process. Both pass the checks made before the PIN; then both take the order's row lock
 * (Orders::forPayment, FOR UPDATE) and the second sees what the first did: one refund is written (201) and the other is refused with
 * 409 orders.refund.line_refunded. Every round: one order_refunds row, one payment_refunds row of 90.00, refunded_total and the payment's
 * refunded_amount 90.00 (never 180.00), each sale reversed once (order_refund_lines.order_line_id and stock_movement_reversals are unique as
 * well), the ledger oracle holds, and the engine detects no deadlock (Innodb_deadlocks, 30 rounds).
 * CommitsForConcurrency: the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('refundRaceDeadlocks')) {
    function refundRaceDeadlocks(): int
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
    $f = $this->f;
    $this->second = makeUser($f->branch, 'manager', ['name' => 'Hanna']);
    // both managers work the till, in the same till shift: a cash refund leaves its drawer
    $f->assign($f->manager, $f->menu->tillStation);
    $f->assign($this->second, $f->menu->tillStation);
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->admin, '864209');
    $this->post = fn (User $as, string $uri, array $body): Closure => function () use ($as, $uri, $body): array {
        loginAs($as);
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson($uri, $body);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key')];
    };
});

it('lets one of two managers refund the same order and refuses the other with 409 orders.refund.line_refunded, never twice, with no deadlock (30 rounds)', function () {
    $f = $this->f;
    $m = $f->menu;
    $deadlocksBefore = refundRaceDeadlocks();
    $failures = [];
    $seen = [];

    foreach (range(1, 30) as $round) {
        stockIn($m->water, $m->display, '1');
        stockIn($m->croissant, $m->display, '1');
        $order = $f->send([$m->line($m->croissant), $m->line($m->water)]); // 90.00
        foreach ($order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
        $paid = ($this->post)($f->cashier, "/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '90.00'])();
        if ($paid['status'] !== 201) {
            $failures[] = "round {$round}: the order was not paid: ".json_encode($paid);

            continue;
        }
        $returns = DB::table('stock_movements')->where('reason', 'return')->count();
        $body = fn (): array => ['client_uuid' => (string) Str::uuid(), 'reason' => 'The order was wrong', 'pin' => '864209'];

        $results = Forks::together([
            ($this->post)($f->manager, "/api/v1/orders/{$order['id']}/refund", $body()),
            ($this->post)($this->second, "/api/v1/orders/{$order['id']}/refund", $body()),
        ]);

        $outcomes = array_map(fn (array $r): string => ($r['ok'] ?? false) ? $r['value']['status'].' '.$r['value']['message_key'] : 'error '.($r['error'] ?? '?'), $results);
        sort($outcomes);
        $row = Order::query()->findOrFail($order['id']);
        $payment = Payment::query()->where('order_id', $order['id'])->sole();
        $refunds = DB::table('order_refunds')->where('order_id', $order['id'])->count();
        $money = DB::table('payment_refunds')->where('order_id', $order['id'])->get();
        $restocked = DB::table('stock_movements')->where('reason', 'return')->count() - $returns;

        $ok = $outcomes === ['201 ', '409 orders.refund.line_refunded']
            && $refunds === 1 && $money->count() === 1 && (string) $money[0]->amount === '90.00'
            && $row->refunded_total->amount() === '90.00' && $payment->refunded_amount->amount() === '90.00' && $restocked === 2;
        if (! $ok) {
            $failures[] = "round {$round}: ".json_encode($outcomes)." refunds {$refunds} money {$money->count()} refunded_total {$row->refunded_total->amount()} payment {$payment->refunded_amount->amount()} returns {$restocked}";
        }
        $winner = ($results[0]['value']['status'] ?? null) === 201 ? 'first' : 'second';
        $seen[$winner] = ($seen[$winner] ?? 0) + 1;
    }

    fwrite(STDERR, 'RefundRaceTest winners seen: '.json_encode($seen).PHP_EOL);
    expect($failures)->toBe([])->and(refundRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:payments', 'feature', 'gate:p4');
