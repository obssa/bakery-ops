<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Shared\Files\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

/*
 * Two tills paying the last balance of one bill at the same moment (phase-4 plan section 7, SplitPaymentRaceTest; contract case 12 pins the
 * lock it relies on, tests/Contract/Payments/OrdersConcurrencyTest.php). Each payment is a real POST through the whole HTTP stack, in its own
 * forked process on its own connection. RecordPayment locks the order (Orders::forPayment, FOR UPDATE), then takes the till's shift shared,
 * then the photo's row FOR UPDATE, so the second payment sees the first one's balance:
 *   - both pay the whole balance of a served order: one is taken and closes the order, the other is told it cannot be paid any more
 *     (409 payments.order.not_payable, status closed; an order not served yet would answer payments.order.settled);
 *   - their parts together are more than the balance: one is taken, the other is told the amount is more than the balance (422
 *     payments.amount.mismatch, with the balance it saw);
 * paid_total is never more than the total, the loser's photo stays free, and the engine detects no deadlock (Innodb_deadlocks, 30 rounds each).
 * CommitsForConcurrency: the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

if (! function_exists('splitPaymentRaceDeadlocks')) {
    function splitPaymentRaceDeadlocks(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
        }
        $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

        return $row === null ? 0 : (int) $row->Value;
    }
}

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->f = OrderFloor::open($this);
    $this->f->assign($this->f->manager, $this->f->menu->tillStation); // the second till is the manager's, in the same till shift
    // a bottle of water, served: 30.00
    $this->order = function (): array {
        $f = $this->f;
        stockIn($f->menu->water, $f->menu->display, '1');
        $order = $f->send([$f->menu->line($f->menu->water)]);
        $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();

        return $order;
    };
    $this->payJob = fn (User $as, array $order, array $body): Closure => function () use ($as, $order, $body): array {
        loginAs($as);
        $response = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order['id']}/payments", $body + ['client_uuid' => (string) Str::uuid()]);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'balance' => $response->json('balance')];
    };
});

it('takes one of two payments of the whole last balance; the other is told the order is closed (30 rounds)', function () {
    $f = $this->f;
    $deadlocksBefore = splitPaymentRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $order = ($this->order)();
        ($this->payJob)($f->cashier, $order, ['channel_code' => 'cash', 'amount' => '10.00'])();
        $photo = ReceiptFiles::upload($this, $f->manager);
        $results = Forks::together([
            ($this->payJob)($f->cashier, $order, ['channel_code' => 'cash', 'amount' => '20.00', 'tendered_amount' => '50.00']),
            ($this->payJob)($f->manager, $order, ['channel_code' => 'telebirr', 'amount' => '20.00', 'reference_no' => "FT{$round}", 'receipt_file_id' => $photo]),
        ]);

        $outcomes = array_map(fn (array $r): string => ($r['ok'] ?? false) ? $r['value']['status'].' '.$r['value']['message_key'] : 'error '.($r['error'] ?? '?'), $results);
        $sorted = $outcomes;
        sort($sorted);
        if ($sorted !== ['201 ', '409 payments.order.not_payable']) {
            $failures[] = "round {$round}: ".json_encode($outcomes);
        }
        $paid = Order::query()->findOrFail($order['id'])->paid_total->amount();
        $count = Payment::query()->where('order_id', $order['id'])->count();
        $digitalWon = str_starts_with($outcomes[1], '201');
        $file = StoredFile::query()->findOrFail($photo);
        if ($paid !== '30.00' || $count !== 2 || ($file->attached_at !== null) !== $digitalWon) {
            $failures[] = "round {$round}: paid {$paid}, {$count} payments, photo attached ".json_encode($file->attached_at !== null).' while the wallet '.($digitalWon ? 'won' : 'lost');
        }
    }

    expect($failures)->toBe([])->and(splitPaymentRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:payments', 'feature');

it('takes one of two part payments that together are more than the balance; the other sees the balance left (30 rounds)', function () {
    $f = $this->f;
    $deadlocksBefore = splitPaymentRaceDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $order = ($this->order)();
        $results = Forks::together([
            ($this->payJob)($f->cashier, $order, ['channel_code' => 'cash', 'amount' => '20.00']),
            ($this->payJob)($f->manager, $order, ['channel_code' => 'cash', 'amount' => '20.00']),
        ]);

        $outcomes = array_map(fn (array $r): string => ($r['ok'] ?? false) ? $r['value']['status'].' '.$r['value']['message_key'].' '.$r['value']['balance'] : 'error '.($r['error'] ?? '?'), $results);
        sort($outcomes);
        if ($outcomes !== ['201  ', '422 payments.amount.mismatch 10.00']) {
            $failures[] = "round {$round}: ".json_encode($outcomes);
        }
        $paid = Order::query()->findOrFail($order['id'])->paid_total->amount();
        if ($paid !== '20.00' || Payment::query()->where('order_id', $order['id'])->count() !== 1) {
            $failures[] = "round {$round}: paid {$paid}";
        }
    }

    expect($failures)->toBe([])->and(splitPaymentRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:payments', 'feature');
