<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Payments\Models\Payment;
use App\Shared\Events\RefundPaid;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'feature');

/*
 * POST /api/v1/orders/{order}/refund (phase-4 plan sections 4.4 and 10.4, step 4.3d; Ramin's rule of 2026-10-28, 10.2 R-C): money of a closed
 * order goes back. The cashier asks (orders.void_request), the PIN of a payments.void holder approves (R-N); each line follows its product's
 * two admin settings (refundable, refund_restock), nobody chooses a disposition; the money back is the lines' line_total, returned over the
 * order's payments last in, first out (or as an `allocation` names it) on the channel each was taken on: a cash share leaves the drawer of the
 * till's running shift (payments.shift.required), a digital share is a transfer made outside the app, recorded with its reference and,
 * optionally, a photo of it (a refund_proof file). Orders' checks come first, so a refused refund spends no PIN attempt; then the PIN; then
 * ONE AuditedAction (order.refund) under the order's row lock: Orders::refund (the stock and order side), the payment_refunds rows and each
 * payment's refunded_amount. 201 {refund, payments, movements, order}; a replay answers 200.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    withActor($f->admin->id);
    $codes = app(ShortCodes::class);
    $codes->set($f->manager, '246802');
    $codes->set($f->cashier, '135791');
    $this->managerPin = '246802';
    $this->cashierPin = '135791';
    stockIn($f->menu->water, $f->menu->display, '20');
    $this->pay = function (array $order, string $channel, string $amount): TestResponse {
        loginAs($this->floor->cashier);
        $body = ['client_uuid' => (string) Str::uuid(), 'channel_code' => $channel, 'amount' => $amount];
        if ($channel !== 'cash') {
            $body += ['reference_no' => 'FT'.random_int(10000000, 99999999), 'receipt_file_id' => ReceiptFiles::upload($this, $this->floor->cashier)];
        }

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order['id']}/payments", $body);
    };
    // a closed order of the given lines (OrdersMenu::line), served and paid: cash for the whole total unless $payments says [channel, amount]
    $this->closed = function (array $lines, ?array $payments = null): array {
        $f = $this->floor;
        $order = $f->send($lines);
        foreach ($order['lines'] as $line) {
            $code = $line['station']['code'] ?? null;
            if ($code === 'kitchen') {
                $f->act('ready', $line['id'], $f->chef)->assertOk();
            } elseif ($code === 'bar') {
                $f->act('ready', $line['id'], $f->barista)->assertOk();
            }
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
        foreach ($payments ?? [['cash', $order['totals']['total']]] as [$channel, $amount]) {
            ($this->pay)($order, $channel, $amount)->assertCreated();
        }
        expect(Order::query()->findOrFail($order['id'])->status->value)->toBe('closed');

        return $order;
    };
    $this->refund = function (int $orderId, array $body = [], ?User $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson(
            "/api/v1/orders/{$orderId}/refund",
            $body + ['client_uuid' => (string) Str::uuid(), 'reason' => 'The customer brought it back', 'pin' => $this->managerPin],
        );
    };
    $this->paymentOf = fn (int $orderId, string $channel): Payment => Payment::query()->where('order_id', $orderId)
        ->whereHas('channel', fn ($q) => $q->where('code', $channel))->sole();
    $this->tillShift = fn (): int => (int) DB::table('shift_instances')->where('station_id', $this->floor->menu->tillStation->id)->whereNotNull('open_key')->value('id');
    $this->written = fn (): array => [
        'order_refunds' => DB::table('order_refunds')->count(), 'payment_refunds' => DB::table('payment_refunds')->count(),
        'movements' => DB::table('stock_movements')->count(), 'audit' => DB::table('audit_log')->where('action', 'order.refund')->count(),
        'refunded' => DB::table('payments')->sum('refunded_amount'),
    ];
    $this->saleOf = fn (array $order, int $itemId): int => (int) DB::table('stock_movements')->where('reason', 'sale')
        ->where('reservation_ref', $this->floor->refOf(collect($order['lines'])->firstWhere('item.id', $itemId)['client_uuid']))->value('id');
});

it('refunds the whole order: the cash goes back out of the drawer, the display-case line is restocked, the made-to-order line is waste, one audit row', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->sandwich)]); // 60.00 + 250.00
    $cashBefore = app(Totals::class)->cash(($this->tillShift)());
    $croissantSale = ($this->saleOf)($order, $m->croissant->id);
    $heard = [];
    Event::listen(RefundPaid::class, function (RefundPaid $e) use (&$heard): void {
        $heard[] = $e->payload();
    });
    $this->travelTo(CarbonImmutable::now()->addMinutes(10)->startOfSecond());
    $uuid = (string) Str::uuid();
    $key = (string) Str::uuid();

    $response = ($this->refund)($order['id'], ['client_uuid' => $uuid], null, $key);

    $response->assertCreated()
        ->assertJsonPath('refund.amount', '310.00')
        ->assertJsonPath('refund.is_full', true)
        ->assertJsonPath('order.id', $order['id'])
        ->assertJsonPath('order.status', 'closed')
        ->assertJsonPath('order.refunded', '310.00')
        ->assertJsonPath('order.net_paid', '0.00')
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.channel_code', 'cash')
        ->assertJsonPath('payments.0.amount', '310.00')
        ->assertJsonPath('payments.0.reference_no', null)
        ->assertJsonCount(1, 'movements')
        ->assertJsonPath('movements.0.item_id', $m->croissant->id)
        ->assertJsonPath('movements.0.qty', '1.0000')
        ->assertJsonPath('movements.0.reverses', $croissantSale);
    $refundId = (int) $response->json('refund.id');
    $payment = ($this->paymentOf)($order['id'], 'cash');
    expect($response->json('payments.0.payment_id'))->toBe($payment->id)
        ->and($payment->refunded_amount->amount())->toBe('310.00')
        ->and($payment->status->value)->toBe('recorded', 'a refund never edits a payment but its refunded_amount')
        ->and(Order::query()->findOrFail($order['id'])->refunded_total->amount())->toBe('310.00')
        ->and(app(Totals::class)->cash(($this->tillShift)())->amount())->toBe($cashBefore->minus('310.00')->amount(), 'the drawer expects the cash handed back no more');
    $row = DB::table('payment_refunds')->sole();
    expect((int) $row->order_refund_id)->toBe($refundId)
        ->and((int) $row->order_id)->toBe($order['id'])
        ->and((int) $row->refunded_by_user_id)->toBe($f->cashier->id)
        ->and((int) $row->approved_by_user_id)->toBe($f->manager->id)
        ->and((int) $row->shift_instance_id)->toBe(($this->tillShift)())
        ->and($row->reference_no)->toBeNull()
        ->and((string) $row->refunded_at)->toBe(CarbonImmutable::now()->format('Y-m-d H:i:s'))
        ->and(DB::table('order_refund_lines')->where('order_refund_id', $refundId)->orderBy('order_line_id')->pluck('disposition')->all())
        ->toBe(['restock', 'waste']);
    $audit = DB::table('audit_log')->where('action', 'order.refund')->sole();
    $context = json_decode((string) $audit->context, true);
    expect((int) $audit->actor_id)->toBe($f->cashier->id)
        ->and($context['approved_by_user_id'])->toBe($f->manager->id)
        ->and($context['order_id'])->toBe($order['id'])
        ->and($context['refund_id'])->toBe($refundId)
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]['refund_id'])->toBe($refundId)
        ->and($heard[0]['order_id'])->toBe($order['id'])
        ->and($heard[0]['order_no'])->toBe($order['order_no'])
        ->and($heard[0]['amount'])->toBe('310.00')
        ->and($heard[0]['by_user_id'])->toBe($f->cashier->id)
        ->and($heard[0]['approved_by_user_id'])->toBe($f->manager->id)
        ->and($heard[0]['approved_by_name'])->toBe('Meron')
        ->and($heard[0]['payment_refunds'])->toBe([['payment_id' => $payment->id, 'channel_code' => 'cash', 'amount' => '310.00']]);

    // the same key answers the same body; another key with the same client_uuid answers the refund it made, writing nothing
    $written = ($this->written)();
    ($this->refund)($order['id'], ['client_uuid' => $uuid], null, $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
        ->assertJsonPath('refund.id', $refundId);
    ($this->refund)($order['id'], ['client_uuid' => $uuid])->assertOk()
        ->assertJsonPath('refund.id', $refundId)
        ->assertJsonPath('payments.0.amount', '310.00')
        ->assertJsonPath('movements.0.reverses', $croissantSale);
    expect(($this->written)())->toBe($written)->and($heard)->toHaveCount(1);
});

it('refunds by line: only the named line comes back, the second refund of the rest makes the order full, a line is refunded once', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water)]); // 60.00 + 30.00
    $water = $f->lineOf($order, $m->water)['id'];
    $croissant = $f->lineOf($order, $m->croissant)['id'];

    ($this->refund)($order['id'], ['lines' => [$water]])->assertCreated()
        ->assertJsonPath('refund.amount', '30.00')
        ->assertJsonPath('refund.is_full', false)
        ->assertJsonPath('payments.0.amount', '30.00')
        ->assertJsonPath('movements.0.item_id', $m->water->id)
        ->assertJsonPath('order.refunded', '30.00')
        ->assertJsonPath('order.net_paid', '60.00');
    $written = ($this->written)();

    ($this->refund)($order['id'], ['lines' => [$water], 'pin' => '000000'])->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.refund.line_refunded')
        ->assertJsonPath('line_ids', [$water]);
    expect(($this->written)())->toBe($written);

    ($this->refund)($order['id'], ['lines' => [$croissant]])->assertCreated()
        ->assertJsonPath('refund.amount', '60.00')
        ->assertJsonPath('refund.is_full', true)
        ->assertJsonPath('payments.0.amount', '60.00');
    expect(($this->paymentOf)($order['id'], 'cash')->refunded_amount->amount())->toBe('90.00')
        ->and(Order::query()->findOrFail($order['id'])->refunded_total->amount())->toBe('90.00');
});

it('takes each line\'s disposition from its product\'s settings at the moment of the refund, nobody chooses it', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water), $m->line($m->latte)]);
    // the admin decided a refunded croissant is not sold again; the water goes back on the shelf; the latte was made to order
    DB::table('items')->where('id', $m->croissant->id)->update(['refund_restock' => false]);
    $waterSale = ($this->saleOf)($order, $m->water->id);

    $response = ($this->refund)($order['id'], ['dispositions' => ['restock']])->assertCreated();

    expect($response->json('movements'))->toHaveCount(1)
        ->and($response->json('movements.0.reverses'))->toBe($waterSale)
        ->and(collect($response->json('refund.lines'))->pluck('disposition', 'item_id')->all())
        ->toEqual([$m->croissant->id => 'waste', $m->water->id => 'restock', $m->latte->id => 'waste']);
});

it('gives the money back last in, first out over a split bill: Telebirr 70.00 then cash 50.00, a 30.00 refund is cash, the next 60.00 takes the cash left then Telebirr', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water), $m->line($m->water)], [['telebirr', '70.00'], ['cash', '50.00']]);
    $waters = collect($order['lines'])->where('item.id', $m->water->id)->pluck('id')->all();
    $croissant = $f->lineOf($order, $m->croissant)['id'];
    $cash = ($this->paymentOf)($order['id'], 'cash');
    $wallet = ($this->paymentOf)($order['id'], 'telebirr');
    $cashBefore = app(Totals::class)->cash(($this->tillShift)());

    ($this->refund)($order['id'], ['lines' => [$waters[0]]])->assertCreated()
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.payment_id', $cash->id)
        ->assertJsonPath('payments.0.amount', '30.00');

    // the next refund needs Telebirr too: a transfer made outside the app, so its reference is asked for, and nothing is written without it
    $written = ($this->written)();
    ($this->refund)($order['id'], ['lines' => [$croissant]])->assertStatus(422)->assertJsonPath('message_key', 'payments.refund.reference_required');
    expect(($this->written)())->toBe($written);

    ($this->refund)($order['id'], ['lines' => [$croissant], 'refund_reference' => 'FT12345678'])->assertCreated()
        ->assertJsonCount(2, 'payments')
        ->assertJsonPath('payments.0.payment_id', $cash->id)
        ->assertJsonPath('payments.0.amount', '20.00')
        ->assertJsonPath('payments.0.reference_no', null)
        ->assertJsonPath('payments.1.payment_id', $wallet->id)
        ->assertJsonPath('payments.1.channel_code', 'telebirr')
        ->assertJsonPath('payments.1.amount', '40.00')
        ->assertJsonPath('payments.1.reference_no', 'FT12345678');
    expect($cash->fresh()->refunded_amount->amount())->toBe('50.00')
        ->and($wallet->fresh()->refunded_amount->amount())->toBe('40.00')
        ->and(app(Totals::class)->cash(($this->tillShift)())->amount())->toBe($cashBefore->minus('50.00')->amount(), 'only the cash shares leave the drawer');
});

it('follows the allocation given for a split bill, and refuses one that does not add up, exceeds what a payment still holds or names another order\'s payment', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water), $m->line($m->water)], [['telebirr', '70.00'], ['cash', '50.00']]);
    $croissant = $f->lineOf($order, $m->croissant)['id'];
    $cash = ($this->paymentOf)($order['id'], 'cash');
    $wallet = ($this->paymentOf)($order['id'], 'telebirr');
    $other = ($this->closed)([$m->line($m->water)]);
    $otherPayment = ($this->paymentOf)($other['id'], 'cash');
    $written = ($this->written)();

    foreach ([
        'short of the refund' => [['payment_id' => $wallet->id, 'amount' => '50.00']],
        'more than the refund' => [['payment_id' => $wallet->id, 'amount' => '40.00'], ['payment_id' => $cash->id, 'amount' => '30.00']],
        'more than the payment holds' => [['payment_id' => $cash->id, 'amount' => '60.00']],
        'another order\'s payment' => [['payment_id' => $otherPayment->id, 'amount' => '30.00'], ['payment_id' => $cash->id, 'amount' => '30.00']],
        'one payment twice' => [['payment_id' => $cash->id, 'amount' => '30.00'], ['payment_id' => $cash->id, 'amount' => '30.00']],
    ] as $what => $allocation) {
        ($this->refund)($order['id'], ['lines' => [$croissant], 'allocation' => $allocation, 'refund_reference' => 'FT12345678'])
            ->assertStatus(422)->assertJsonPath('message_key', 'payments.refund.allocation_invalid');
        expect(($this->written)())->toBe($written, $what);
    }

    ($this->refund)($order['id'], ['lines' => [$croissant], 'allocation' => [['payment_id' => $wallet->id, 'amount' => '60.00']], 'refund_reference' => 'FT12345678'])
        ->assertCreated()
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.payment_id', $wallet->id)
        ->assertJsonPath('payments.0.amount', '60.00');
    expect($wallet->fresh()->refunded_amount->amount())->toBe('60.00')->and($cash->fresh()->refunded_amount->amount())->toBe('0.00');
});

it('needs the till\'s open shift for a cash share: without one nothing is written, the stock and the order included', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant)]);
    $f->assign($f->cashier); // the cashier now works nowhere: she carries no shift
    $written = ($this->written)();

    ($this->refund)($order['id'])->assertStatus(409)->assertJsonPath('message_key', 'payments.shift.required');

    expect(($this->written)())->toBe($written)
        ->and(Order::query()->findOrFail($order['id'])->refunded_total->amount())->toBe('0.00');
    $f->assign($f->cashier, $m->tillStation);
    ($this->refund)($order['id'])->assertCreated()->assertJsonPath('payments.0.channel_code', 'cash');
});

it('records a digital share with the reference of the transfer and its photo, which the refund row claims', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant)], [['telebirr', '60.00']]);
    $wrong = ReceiptFiles::upload($this, $f->cashier);
    $old = ReceiptFiles::row($f->branch->id, $f->cashier->id, ['purpose' => 'refund_proof', 'created_at' => CarbonImmutable::now()->subDay()]);
    $written = ($this->written)();

    ($this->refund)($order['id'], ['refund_reference' => 'FT87654321', 'refund_proof_file_id' => $wrong])->assertStatus(422)
        ->assertJsonPath('message_key', 'payments.refund.proof_invalid')->assertJsonPath('reason', 'wrong_purpose');
    ($this->refund)($order['id'], ['refund_reference' => 'FT87654321', 'refund_proof_file_id' => $old->id])->assertStatus(422)
        ->assertJsonPath('reason', 'expired');
    ($this->refund)($order['id'], ['refund_reference' => 'FT87654321', 'refund_proof_file_id' => 999999])->assertStatus(422)
        ->assertJsonPath('reason', 'not_found');
    expect(($this->written)())->toBe($written);

    $proof = ReceiptFiles::upload($this, $f->cashier, null, 'refund_proof');
    $response = ($this->refund)($order['id'], ['refund_reference' => 'FT87654321', 'refund_proof_file_id' => $proof])->assertCreated()
        ->assertJsonPath('payments.0.channel_code', 'telebirr')
        ->assertJsonPath('payments.0.reference_no', 'FT87654321')
        ->assertJsonPath('payments.0.proof_file_id', $proof);
    $row = DB::table('payment_refunds')->sole();
    $file = DB::table('stored_files')->where('id', $proof)->sole();
    expect((int) $row->proof_file_id)->toBe($proof)
        ->and($row->reference_no)->toBe('FT87654321')
        ->and($file->attached_type)->toBe('payment_refund')
        ->and((int) $file->attached_id)->toBe((int) $row->id)
        ->and($response->json('payments.0.id'))->toBe((int) $row->id);

    // a photo claimed once is nobody else's
    $second = ($this->closed)([$m->line($m->water)], [['telebirr', '30.00']]);
    ($this->refund)($second['id'], ['refund_reference' => 'FT11112222', 'refund_proof_file_id' => $proof])->assertStatus(422)
        ->assertJsonPath('reason', 'attached');
});

it('refuses outside the window, a partial refund of an order with a discount and a product that is not refundable, before it asks for the PIN', function () {
    $f = $this->floor;
    $m = $f->menu;
    $wrongPin = ['pin' => '000000'];

    // R-D: an order with a discount goes back whole or not at all
    $discounted = $f->send([$m->line($m->croissant), $m->line($m->water)]);
    foreach ($discounted['lines'] as $line) {
        $f->act('served', $line['id'], $f->waiter)->assertOk();
    }
    DB::table('orders')->where('id', $discounted['id'])->update(['discount' => '10.00', 'total' => '80.00']);
    ($this->pay)($discounted, 'cash', '80.00')->assertCreated();
    ($this->refund)($discounted['id'], ['lines' => [$f->lineOf($discounted, $m->water)['id']]] + $wrongPin)->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.refund.partial_not_allowed');

    // the admin's settings: a product that may not be refunded is refused by line, skipped by a whole-order refund, and none left is not_refundable
    $order = ($this->closed)([$m->line($m->croissant)]);
    DB::table('items')->where('id', $m->croissant->id)->update(['refundable' => false]);
    ($this->refund)($order['id'], ['lines' => [$f->lineOf($order, $m->croissant)['id']]] + $wrongPin)->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.refund.line_not_refundable');
    ($this->refund)($order['id'], $wrongPin)->assertStatus(409)->assertJsonPath('message_key', 'orders.refund.not_refundable');
    ($this->refund)($order['id'], ['lines' => [$f->lineOf($discounted, $m->water)['id']]] + $wrongPin)->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.refund.line_invalid');
    DB::table('items')->where('id', $m->croissant->id)->update(['refundable' => true]);

    // R-O: 72 hours after the order closed
    $this->travelTo(CarbonImmutable::now()->addHours(73));
    ($this->refund)($order['id'], $wrongPin)->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.refund.window_closed')
        ->assertJsonPath('hours', 72);

    expect(DB::table('order_refunds')->count())->toBe(0)->and(DB::table('payment_refunds')->count())->toBe(0);
});

it('refuses a PIN that is not a refund approver\'s with 403 and locks the prompt after five wrong ones with 423, writing nothing', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant)]);

    ($this->refund)($order['id'], ['pin' => $this->cashierPin])->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    foreach (range(1, 4) as $try) {
        ($this->refund)($order['id'], ['pin' => '00000'.$try])->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    }
    ($this->refund)($order['id'])->assertStatus(423)->assertJsonPath('message_key', 'identity.short_code.locked');

    expect(DB::table('order_refunds')->count())->toBe(0)
        ->and(DB::table('payment_refunds')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'order.refund')->count())->toBe(0);
});

it('asks for a reason and a PIN of 4 to 6 digits, a UUID and line ids, and finds only the branch\'s own orders', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant)]);

    ($this->refund)($order['id'], ['reason' => '   '])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonValidationErrors('reason');
    ($this->refund)($order['id'], ['pin' => '12'])->assertStatus(422)->assertJsonValidationErrors('pin');
    ($this->refund)($order['id'], ['client_uuid' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_uuid');
    ($this->refund)($order['id'], ['lines' => ['x']])->assertStatus(422)->assertJsonValidationErrors('lines.0');
    ($this->refund)($order['id'], ['allocation' => [['payment_id' => 1]]])->assertStatus(422)->assertJsonValidationErrors('allocation.0.amount');

    $elsewhere = Order::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->refund)($elsewhere->id)->assertNotFound();
    loginAs($f->waiter);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order['id']}/refund", [
        'client_uuid' => (string) Str::uuid(), 'reason' => 'x', 'pin' => $this->managerPin,
    ])->assertForbidden();
    expect(DB::table('order_refunds')->count())->toBe(0);
});
