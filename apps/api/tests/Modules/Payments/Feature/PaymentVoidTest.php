<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Payments\Models\Payment;
use App\Shared\Events\PaymentVoided;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

pest()->group('module:payments', 'feature');

/*
 * POST /api/v1/payments/{payment}/void (phase-4 plan sections 4.4 and 10.4, step 4.3c): a wrong payment is voided before the order is paid. The
 * asker holds orders.void_request (admin, manager, cashier; the person acting by short code on a shared till is the one checked), the PIN of a
 * payments.void holder (a manager, the admin) approves it, as VoidOrder's PIN rules: the payment's state is checked first so a refused void
 * spends no PIN attempt, then the PIN (Identity's verifyPinFor, outside the transaction, so a wrong one counts toward the lockout), then one
 * AuditedAction (payment.void) under the order's row lock: the payment goes voided (voided_by = the asker, the approver in the audit context and
 * on the timeline) and PaymentVoided is dispatched inside the transaction, where Orders' ReversePayment takes the amount out of paid_total,
 * unsettles a settled order and writes payment.voided on the timeline. Nothing was sold yet, so no stock moves. Refused: a paid or closed order
 * (409 payments.void.order_closed: refund it instead), a payment money already went back from (409 payments.void.refunded; refunded_amount > 0
 * stands in for a refund until step 4.3d), a voided one (409 state_machine.illegal_transition). The PIN is never stored, logged or audited.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    withActor($f->admin->id);
    $codes = app(ShortCodes::class);
    $codes->set($f->manager, '246802');
    $codes->set($f->admin, '864209');
    $codes->set($f->cashier, '135791');
    $this->managerPin = '246802';
    $this->cashierPin = '135791';
    $this->pay = function (array $order, string $amount, ?User $as = null): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $amount]);
    };
    $this->void = function (int $paymentId, array $body = [], ?User $as = null, ?string $key = null, array $headers = []): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()] + $headers)
            ->postJson("/api/v1/payments/{$paymentId}/void", $body + ['reason' => 'Keyed 20 instead of 2', 'pin' => $this->managerPin]);
    };
    // two bottles of water, served: 60.00
    $this->served = function (): array {
        $f = $this->floor;
        $order = $f->send([$f->menu->line($f->menu->water, '2')]);
        $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();

        return $order;
    };
    $this->order = fn (int $id): Order => Order::query()->findOrFail($id);
    $this->events = fn (int $orderId): array => OrderEvent::query()->where('order_id', $orderId)->orderBy('id')->pluck('event')->map(fn ($e) => $e->value)->all();
    $this->tillShift = fn (): int => (int) DB::table('shift_instances')->where('station_id', $this->floor->menu->tillStation->id)->whereNotNull('open_key')->value('id');
});

it('voids a part payment of a served order with a manager PIN, and the order is paid again and closes once', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    $before = ($this->order)($order['id']);
    expect($before->paid_total->amount())->toBe('20.00');
    $cashBefore = app(Totals::class)->cash(($this->tillShift)())->amount();
    $this->travelTo(CarbonImmutable::now()->addMinutes(4)->startOfSecond());

    $response = ($this->void)($paymentId, ['reason' => '  Keyed 20 instead of 2  ']);

    $response->assertOk()
        ->assertJsonPath('id', $paymentId)
        ->assertJsonPath('status', 'voided')
        ->assertJsonPath('void_reason', 'Keyed 20 instead of 2')
        ->assertJsonPath('voided_at', CarbonImmutable::now()->toIso8601String())
        ->assertJsonPath('order.id', $order['id']);
    $payment = Payment::query()->findOrFail($paymentId);
    expect($payment->status->value)->toBe('voided')
        ->and($payment->voided_by_user_id)->toBe($f->cashier->id)
        ->and($payment->amount->amount())->toBe('20.00', 'the row keeps what was taken; the status says it does not count');

    $row = ($this->order)($order['id']);
    expect($row->paid_total->amount())->toBe('0.00')
        ->and($row->status->value)->toBe('served')
        ->and($row->settled_at)->toBeNull()
        ->and($row->version)->toBeGreaterThan($before->version)
        ->and(app(Totals::class)->cash(($this->tillShift)())->amount())->toBe(Money::of($cashBefore)->minus('20.00')->amount(), 'the drawer expects the voided cash no more');
    $voided = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'payment.voided')->sole();
    expect($voided->user_id)->toBe($f->cashier->id)
        ->and($voided->approved_by_user_id)->toBe($f->manager->id)
        ->and($voided->payload)->toBe([
            'payment_id' => $paymentId,
            'channel_code' => 'cash',
            'amount' => '20.00',
            'reason' => 'Keyed 20 instead of 2',
            'by_user_id' => $f->cashier->id,
            'approved_by_user_id' => $f->manager->id,
        ]);
    $audit = DB::table('audit_log')->where('action', 'payment.void')->sole();
    expect($audit->actor_id)->toBe($f->cashier->id)
        ->and($audit->subject_id)->toBe($paymentId)
        ->and(json_decode((string) $audit->context, true))->toBe(['reason' => 'Keyed 20 instead of 2', 'approved_by_user_id' => $f->manager->id, 'order_id' => $order['id']])
        ->and(json_decode((string) $audit->after, true)['status'])->toBe('voided');

    // pay again: the whole bill this time
    $sales = DB::table('stock_movements')->where('reason', 'sale')->count();
    ($this->pay)($order, '60.00')->assertCreated()->assertJsonPath('order.status', 'closed')->assertJsonPath('order.paid', '60.00');
    expect(($this->order)($order['id'])->paid_total->amount())->toBe('60.00')
        ->and(DB::table('stock_movements')->where('reason', 'sale')->count())->toBe($sales + 1, 'the water is sold once')
        ->and(array_count_values(($this->events)($order['id'])))->toMatchArray(['payment.recorded' => 2, 'payment.voided' => 1, 'order.paid' => 1, 'order.closed' => 1]);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
});

it('unsettles an order that was paid in full before the food came, and the next payment settles it again', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->latte)]);
    $paymentId = (int) ($this->pay)($order, $order['totals']['total'])->assertCreated()->json('payment.id');
    expect(($this->order)($order['id'])->settled_at)->not->toBeNull();

    ($this->void)($paymentId)->assertOk()->assertJsonPath('status', 'voided');

    $row = ($this->order)($order['id']);
    expect($row->settled_at)->toBeNull()->and($row->paid_total->amount())->toBe('0.00')->and($row->status->value)->toBe('sent');
    ($this->pay)($order, $order['totals']['total'])->assertCreated();
    expect(($this->order)($order['id'])->settled_at)->not->toBeNull()
        ->and(array_count_values(($this->events)($order['id']))['order.settled'])->toBe(2);
});

it('voids a disputed payment and marks the dispute resolved', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    loginAs($f->manager);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/payments/{$paymentId}/dispute", ['reason' => 'Counterfeit note'])->assertOk();

    ($this->void)($paymentId)->assertOk()->assertJsonPath('status', 'voided')->assertJsonPath('dispute_reason', 'Counterfeit note');

    expect(Payment::query()->findOrFail($paymentId)->dispute_resolved_at)->not->toBeNull()
        ->and(($this->order)($order['id'])->paid_total->amount())->toBe('0.00');
});

it('refuses a payment of a paid and closed order: it is refunded, not voided, and no PIN attempt is spent', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '60.00')->assertCreated()->json('payment.id');
    expect(($this->order)($order['id'])->status->value)->toBe('closed');

    foreach (range(1, 6) as $try) {
        ($this->void)($paymentId, ['pin' => '00000'.$try])->assertStatus(409)
            ->assertJsonPath('message_key', 'payments.void.order_closed')->assertJsonPath('status', 'closed');
    }
    ($this->void)($paymentId)->assertStatus(409)->assertJsonPath('message_key', 'payments.void.order_closed');

    expect(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded')
        ->and(($this->order)($order['id'])->paid_total->amount())->toBe('60.00')
        ->and(DB::table('audit_log')->where('action', 'payment.void')->count())->toBe(0);
    // the lockout counter was never touched: another payment can still be voided with the right PIN
    $other = ($this->served)();
    $otherId = (int) ($this->pay)($other, '10.00')->assertCreated()->json('payment.id');
    ($this->void)($otherId)->assertOk();
});

it('refuses a payment money already went back from (refunded_amount > 0 until step 4.3d)', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    DB::table('payments')->where('id', $paymentId)->update(['refunded_amount' => '5.00']);

    ($this->void)($paymentId)->assertStatus(409)->assertJsonPath('message_key', 'payments.void.refunded')->assertJsonPath('refunded_amount', '5.00');

    expect(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded')
        ->and(($this->order)($order['id'])->paid_total->amount())->toBe('20.00');
});

it('refuses a payment that is voided already, and replays the same key with the same body', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    $key = (string) Str::uuid();

    $first = ($this->void)($paymentId, [], null, $key)->assertOk();
    $counts = [DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $second = ($this->void)($paymentId, [], null, $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and([DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($counts);
    ($this->void)($paymentId)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')->assertJsonPath('from', 'voided')->assertJsonPath('to', 'voided');
    expect(($this->order)($order['id'])->paid_total->amount())->toBe('0.00', 'taken out once');
});

it('voids the part payment of an order that was voided itself, so the money it took goes back', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->latte)]);
    $paymentId = (int) ($this->pay)($order, '10.00')->assertCreated()->json('payment.id');
    loginAs($f->cashier);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Customer left', 'pin' => $this->managerPin])->assertOk();

    ($this->void)($paymentId)->assertOk()->assertJsonPath('status', 'voided');

    $row = ($this->order)($order['id']);
    expect($row->status->value)->toBe('voided')->and($row->paid_total->amount())->toBe('0.00');
});

it('refuses the PIN of a cashier, of nobody and of another branch, and writes nothing', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    $other = actInBranch(Branch::factory()->create());
    withActor(makeUser($other, 'admin')->id);
    app(ShortCodes::class)->set(makeUser($other, 'manager'), '975310');
    actInBranch($f->branch);
    $before = [DB::table('order_events')->count(), DB::table('audit_log')->where('action', 'payment.void')->count()];

    foreach ([$this->cashierPin, '000000', '975310'] as $pin) {
        ($this->void)($paymentId, ['pin' => $pin])->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    }

    expect([DB::table('order_events')->count(), DB::table('audit_log')->where('action', 'payment.void')->count()])->toBe($before)
        ->and(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded')
        ->and(($this->order)($order['id'])->paid_total->amount())->toBe('20.00');
});

it('counts wrong PINs toward the lockout: five are refused, the sixth is locked even with the right PIN', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    foreach (range(1, 5) as $try) {
        ($this->void)($paymentId, ['pin' => '00000'.$try])->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    }

    ($this->void)($paymentId)->assertStatus(423)->assertJsonPath('message_key', 'identity.short_code.locked');
    expect(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded');
});

it('lets the manager and the admin ask too, and the admin\'s PIN approve', function () {
    $f = $this->floor;
    $a = ($this->served)();
    $b = ($this->served)();
    $aId = (int) ($this->pay)($a, '20.00')->assertCreated()->json('payment.id');
    $bId = (int) ($this->pay)($b, '20.00')->assertCreated()->json('payment.id');

    ($this->void)($aId, ['pin' => '864209'], $f->manager)->assertOk();
    ($this->void)($bId, ['pin' => $this->managerPin], $f->admin)->assertOk();

    expect(Payment::query()->findOrFail($aId)->voided_by_user_id)->toBe($f->manager->id)
        ->and(OrderEvent::query()->where('order_id', $a['id'])->where('event', 'payment.voided')->value('approved_by_user_id'))->toBe($f->admin->id)
        ->and(Payment::query()->findOrFail($bId)->voided_by_user_id)->toBe($f->admin->id);
});

it('is for the asking roles only, and the person acting by short code on a shared till is the one checked', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    foreach ([$f->waiter, $f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        ($this->void)($paymentId, [], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }

    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Till']);
    $waiterSession = app(ActingAsSession::class)->start($f->waiter, $device);
    ($this->void)($paymentId, [], $f->cashier, null, ['X-Acting-Session' => (string) $waiterSession->id, 'X-Device-Id' => $device->fingerprint])
        ->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    expect(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded');

    $hana = makeUser($f->branch, 'cashier', ['name' => 'Hana']);
    $session = app(ActingAsSession::class)->start($hana, $device);
    ($this->void)($paymentId, [], $f->cashier, null, ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint])->assertOk();
    $audit = DB::table('audit_log')->where('action', 'payment.void')->sole();
    expect(Payment::query()->findOrFail($paymentId)->voided_by_user_id)->toBe($hana->id)
        ->and([$audit->actor_id, $audit->acting_user_id])->toBe([$f->cashier->id, $hana->id]);
});

it('asks for a reason and a PIN of the right shape', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');

    foreach (['', '   ', str_repeat('x', 256)] as $reason) {
        $response = ($this->void)($paymentId, ['reason' => $reason])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
        expect($response->json('errors.reason.0'))->toBe(__('payments.void.reason_required'));
    }
    foreach (['', 'abcd', '123', '1234567'] as $pin) {
        ($this->void)($paymentId, ['pin' => $pin])->assertStatus(422)->assertJsonStructure(['errors' => ['pin']]);
    }
    ($this->void)($paymentId, ['pin' => ['2468']])->assertStatus(422)->assertJsonStructure(['errors' => ['pin']]);

    expect(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded');
});

it('never stores, logs or audits the PIN', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    ($this->void)($paymentId, ['pin' => '000000'])->assertStatus(403);

    ($this->void)($paymentId)->assertOk();

    foreach (['audit_log', 'order_events', 'payments', 'notifications'] as $table) {
        $dump = json_encode(DB::table($table)->get());
        expect($dump)->not->toContain($this->managerPin, $table)->not->toContain('"pin"', $table);
    }
    expect(json_encode(DB::table('idempotency_keys')->pluck('response_body')))->not->toContain($this->managerPin);
});

it('dispatches PaymentVoided once, inside the void transaction, and a failure there rolls the void back', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(PaymentVoided::class, function (PaymentVoided $e) use (&$seen): void {
        $seen[] = [$e->payload(), DB::connection()->transactionLevel()];
    });

    ($this->void)($paymentId)->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBeGreaterThan($baseline, 'inside the transaction')
        ->and($seen[0][0])->toBe([
            'payment_id' => $paymentId,
            'order_id' => $order['id'],
            'channel_code' => 'cash',
            'amount' => '20.00',
            'reason' => 'Keyed 20 instead of 2',
            'by_user_id' => $f->cashier->id,
            'approved_by_user_id' => $f->manager->id,
        ]);

    // a listener that fails takes the void with it
    $second = ($this->served)();
    $secondId = (int) ($this->pay)($second, '20.00')->assertCreated()->json('payment.id');
    Event::listen(PaymentVoided::class, function (): never {
        throw new RuntimeException('listener failed');
    });
    ($this->void)($secondId)->assertStatus(500);

    expect(Payment::query()->findOrFail($secondId)->status->value)->toBe('recorded')
        ->and(($this->order)($second['id'])->paid_total->amount())->toBe('20.00')
        ->and(($this->events)($second['id']))->not->toContain('payment.voided')
        ->and(DB::table('audit_log')->where('action', 'payment.void')->count())->toBe(1);
});

it('does not find another branch\'s payment', function () {
    $order = ($this->served)();
    $paymentId = (int) ($this->pay)($order, '20.00')->assertCreated()->json('payment.id');
    $stranger = makeUser(Branch::factory()->create(), 'cashier');

    ($this->void)(987654)->assertNotFound();
    ($this->void)($paymentId, [], $stranger)->assertNotFound();
    expect(Payment::query()->findOrFail($paymentId)->status->value)->toBe('recorded');
});
