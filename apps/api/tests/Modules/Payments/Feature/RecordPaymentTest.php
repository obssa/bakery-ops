<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

/*
 * POST /orders/{order}/payments (phase-1 plan sections 4 and 10, step 1.4; payments.take: admin, manager, cashier).
 * Phase 1 takes one cash payment for the whole balance: tendered >= amount gives change; every digital channel is
 * refused until Phase 4 (receipt photos); a payment may come before the food (the order is then settled and closes when
 * its last line is served). Orders' side of PaymentRecorded is in tests/Contract/Payments and ApplyPaymentTest.
 * Prices of the OrdersMenu: sandwich 250.00, latte 120.00, croissant 60.00, water 30.00.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->pay = function (array $order, array $body = [], ?User $as = null, ?string $key = null, array $headers = []): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()] + $headers)->postJson(
            "/api/v1/orders/{$order['id']}/payments",
            $body + ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $order['totals']['total']],
        );
    };
    // a served order: a sandwich, a croissant and a bottle of water = 340.00
    $this->served = function (): array {
        $f = $this->floor;
        $m = $f->menu;
        $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant), $m->line($m->water)]);
        $f->act('ready', $f->lineOf($order, $m->sandwich)['id'], $f->chef)->assertOk();
        foreach ($order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }

        return $order;
    };
    $this->row = fn (int $id): Order => Order::query()->findOrFail($id);
    $this->payments = fn (): int => Payment::query()->count();
});

it('takes a cash payment for a served order: paid and closed, display-case lines sold, change due', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->served)();
    expect($order['totals']['total'])->toBe('340.00');
    $croissantRef = $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']);
    $clientUuid = (string) Str::uuid();

    $response = ($this->pay)($order, ['client_uuid' => $clientUuid, 'amount' => '340.00', 'tendered_amount' => '500.00']);

    $response->assertCreated()
        ->assertJsonPath('payment.channel_code', 'cash')
        ->assertJsonPath('payment.client_uuid', $clientUuid)
        ->assertJsonPath('payment.amount', '340.00')
        ->assertJsonPath('payment.tendered_amount', '500.00')
        ->assertJsonPath('payment.change_amount', '160.00')
        ->assertJsonPath('payment.status', 'recorded')
        ->assertJsonPath('payment.received_by', $f->cashier->id)
        ->assertJsonPath('order.status', 'closed')
        ->assertJsonPath('order.total', '340.00')
        ->assertJsonPath('order.paid', '340.00')
        ->assertJsonPath('order.balance', '0.00');
    expect($response->json('payment.received_at'))->toBeString()
        ->and($response->json('payment.id'))->toBeInt();

    $payment = Payment::query()->sole();
    expect($payment->order_id)->toBe($order['id'])
        ->and($payment->amount->amount())->toBe('340.00')
        ->and($payment->currency)->toBe('ETB')
        ->and($payment->status->value)->toBe('recorded')
        ->and($payment->received_by_user_id)->toBe($f->cashier->id)
        ->and($payment->acting_user_id)->toBeNull()
        ->and($payment->reference_no)->toBeNull()
        ->and($payment->receipt_photo_key)->toBeNull();
    $row = ($this->row)($order['id']);
    expect($row->status->value)->toBe('closed')
        ->and($row->paid_total->amount())->toBe('340.00')
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '-1.0000'])
        ->and(OrderEvent::query()->where('order_id', $order['id'])->orderBy('id')->pluck('event')->map(fn ($e) => $e->value)->all())
        ->toMatchArray(['order.settled', 'order.paid', 'order.closed']);
})->group('module:payments', 'feature');

it('defaults the tendered cash to the amount: no change', function () {
    $order = ($this->served)();

    ($this->pay)($order, ['amount' => '340.00'])->assertCreated()
        ->assertJsonPath('payment.tendered_amount', '340.00')
        ->assertJsonPath('payment.change_amount', '0.00');
})->group('module:payments', 'feature');

it('takes a payment before the food: the order is settled and closes when its last line is served', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    $croissant = $f->lineOf($order, $m->croissant);
    $croissantRef = $f->refOf($croissant['client_uuid']);

    ($this->pay)($order, ['amount' => '310.00', 'tendered_amount' => '400.00'])->assertCreated()
        ->assertJsonPath('order.status', 'sent')
        ->assertJsonPath('order.balance', '0.00')
        ->assertJsonPath('payment.change_amount', '90.00');
    expect(($this->row)($order['id'])->settled_at)->not->toBeNull()
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([]);

    $f->act('ready', $f->lineOf($order, $m->sandwich)['id'], $f->chef)->assertOk();
    $f->act('served', $f->lineOf($order, $m->sandwich)['id'], $f->waiter)->assertOk();
    $f->act('served', $croissant['id'], $f->waiter)->assertOk()->assertJsonPath('order.status', 'closed');
    expect($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '-1.0000']);
})->group('module:payments', 'feature');

it('refuses a tendered amount below the amount, writing nothing', function () {
    $order = ($this->served)();
    $before = [DB::table('audit_log')->count(), DB::table('order_events')->count()];

    ($this->pay)($order, ['amount' => '340.00', 'tendered_amount' => '339.99'])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'payments.tendered.too_low')
        ->assertJsonPath('message_en', fn ($m): bool => is_string($m) && $m !== '' && $m !== 'payments.tendered.too_low')
        ->assertJsonPath('message_am', fn ($m): bool => is_string($m) && $m !== '' && $m !== 'payments.tendered.too_low')
        ->assertJsonPath('amount', '340.00')
        ->assertJsonPath('tendered_amount', '339.99');

    expect(($this->payments)())->toBe(0)
        ->and([DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($before)
        ->and(($this->row)($order['id'])->paid_total->amount())->toBe('0.00');
})->group('module:payments', 'feature');

it('takes the whole balance only: a part payment and an overpayment are refused', function () {
    $order = ($this->served)();

    foreach (['339.99', '100.00', '340.01', '1000.00'] as $amount) {
        ($this->pay)($order, ['amount' => $amount, 'tendered_amount' => '2000.00'])
            ->assertStatus(422)
            ->assertJsonPath('message_key', 'payments.amount.mismatch')
            ->assertJsonPath('balance', '340.00')
            ->assertJsonPath('amount', $amount);
    }
    expect(($this->payments)())->toBe(0)->and(($this->row)($order['id'])->status->value)->toBe('served');
})->group('module:payments', 'feature');

it('refuses every digital channel until receipts can be photographed (Phase 4)', function () {
    $order = ($this->served)();

    foreach (['telebirr', 'cbe_birr', 'cbe_bank', 'awash', 'dashen', 'abyssinia', 'coop'] as $code) {
        ($this->pay)($order, ['channel_code' => $code, 'reference_no' => 'FT123456'])
            ->assertStatus(422)
            ->assertJsonPath('message_key', 'payments.channel.photo_required')
            ->assertJsonPath('channel_code', $code);
    }
    expect(($this->payments)())->toBe(0)->and(($this->row)($order['id'])->status->value)->toBe('served');
})->group('module:payments', 'feature');

it('refuses a disabled channel, an unknown one, and a reference a channel asks for', function () {
    $f = $this->floor;
    $order = ($this->served)();

    PaymentChannel::query()->where('code', 'cash')->update(['is_enabled' => false]);
    ($this->pay)($order)->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.disabled')->assertJsonPath('channel_code', 'cash');
    // a disabled digital channel is disabled first, not "photo required"
    PaymentChannel::query()->where('code', 'telebirr')->update(['is_enabled' => false]);
    ($this->pay)($order, ['channel_code' => 'telebirr'])->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.disabled');

    ($this->pay)($order, ['channel_code' => 'barter'])->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.unknown');

    // a channel the admin set up to ask for a reference but no photo
    PaymentChannel::query()->where('code', 'cash')->update(['is_enabled' => true, 'requires_reference' => true]);
    ($this->pay)($order)->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.reference_required');
    ($this->pay)($order, ['reference_no' => '   '])->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.reference_required');
    ($this->pay)($order, ['reference_no' => 'R-77'])->assertCreated();
    expect(($this->payments)())->toBe(1)->and(Payment::query()->sole()->reference_no)->toBe('R-77');

    // a removed channel is no channel
    $other = $f->send([$f->menu->line($f->menu->water)]);
    PaymentChannel::query()->where('code', 'cash')->delete();
    ($this->pay)($other)->assertStatus(422)->assertJsonPath('message_key', 'payments.channel.unknown');
})->group('module:payments', 'feature');

it('refuses an order that cannot be paid: open, cancelled, voided, closed', function () {
    $f = $this->floor;
    $m = $f->menu;
    $open = $f->send([$m->line($m->water)], null, ['send' => false]);
    $cancelled = $f->send([$m->line($m->water)], null, ['send' => false]);
    loginAs($f->waiter);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$cancelled['id']}/cancel", [])->assertOk();
    $voided = $f->send([$m->line($m->water)]);
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    loginAs($f->cashier);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$voided['id']}/void", ['reason' => 'Customer left', 'pin' => '246802'])->assertOk();
    $closed = $f->send([$m->line($m->water)]);
    $f->act('served', $closed['lines'][0]['id'], $f->waiter)->assertOk();
    ($this->pay)($closed)->assertCreated()->assertJsonPath('order.status', 'closed');

    foreach ([[$open, 'open'], [$cancelled, 'cancelled'], [$voided, 'voided'], [$closed, 'closed']] as [$order, $status]) {
        ($this->pay)($order, ['amount' => '30.00'])
            ->assertStatus(409)
            ->assertJsonPath('message_key', 'payments.order.not_payable')
            ->assertJsonPath('status', $status);
    }
    expect(($this->payments)())->toBe(1);
})->group('module:payments', 'feature');

it('refuses a second payment for an order that is already settled', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->sandwich)]);
    ($this->pay)($order)->assertCreated();

    ($this->pay)($order)->assertStatus(409)->assertJsonPath('message_key', 'payments.order.settled');
    expect(($this->payments)())->toBe(1)->and(($this->row)($order['id'])->paid_total->amount())->toBe('250.00');
})->group('module:payments', 'feature');

it('replays under the same key with the same body and writes once', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $key = (string) Str::uuid();
    $body = ['client_uuid' => (string) Str::uuid(), 'tendered_amount' => '400.00'];

    $first = ($this->pay)($order, $body, null, $key)->assertCreated();
    $second = ($this->pay)($order, $body, null, $key);

    expect($second->getContent())->toBe($first->getContent())
        ->and(($this->payments)())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payment.record')->count())->toBe(1)
        ->and($f->level($f->menu->croissant, $f->menu->display)->reserved->toString())->toBe('0.0000');
})->group('module:payments', 'feature');

it('answers the same payment for a client_uuid already used, under a new key, without taking the money twice', function () {
    $order = ($this->served)();
    $body = ['client_uuid' => (string) Str::uuid(), 'tendered_amount' => '400.00'];

    $first = ($this->pay)($order, $body)->assertCreated();
    $again = ($this->pay)($order, $body);

    $again->assertOk()
        ->assertJsonPath('payment.id', $first->json('payment.id'))
        ->assertJsonPath('payment.change_amount', '60.00')
        ->assertJsonPath('order.status', 'closed')
        ->assertJsonPath('order.paid', '340.00');
    expect(($this->payments)())->toBe(1)
        ->and(($this->row)($order['id'])->paid_total->amount())->toBe('340.00')
        ->and(AuditLog::query()->where('action', 'payment.record')->count())->toBe(1);
})->group('module:payments', 'feature');

it('refuses a client_uuid that belongs to another order', function () {
    $f = $this->floor;
    $a = $f->send([$f->menu->line($f->menu->water)]);
    $b = $f->send([$f->menu->line($f->menu->water)]);
    $clientUuid = (string) Str::uuid();
    ($this->pay)($a, ['client_uuid' => $clientUuid])->assertCreated();

    ($this->pay)($b, ['client_uuid' => $clientUuid])->assertStatus(409)->assertJsonPath('message_key', 'payments.client_uuid.reused');
    expect(($this->payments)())->toBe(1)->and(($this->row)($b['id'])->paid_total->amount())->toBe('0.00');
})->group('module:payments', 'feature');

it('validates the body', function () {
    $order = ($this->served)();
    $send = fn (array $body) => ($this->pay)($order, $body);

    $send(['client_uuid' => 'nope'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $send(['channel_code' => ''])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    foreach (['abc', '-5.00', '0', '0.00', '12.345', '1e3', 340.0] as $amount) {
        $send(['amount' => $amount])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
    $send(['tendered_amount' => '-1.00'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $send(['tendered_amount' => 'lots'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $send(['reference_no' => str_repeat('x', 65)])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(($this->payments)())->toBe(0);
})->group('module:payments', 'feature');

it('is for people who may take payments: a waiter, and a waiter acting on a shared till, are refused', function () {
    $f = $this->floor;
    $order = ($this->served)();

    ($this->pay)($order, [], $f->waiter)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');

    // the till account is a cashier's, but the person who unlocked it by short code is a waiter
    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Till']);
    $session = app(ActingAsSession::class)->start($f->waiter, $device);
    ($this->pay)($order, [], $f->cashier, null, ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint])
        ->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    expect(($this->payments)())->toBe(0);
})->group('module:payments', 'feature');

it('stamps the person acting by short code, the till account, the device and the audit row', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $other = makeUser($f->branch, 'cashier', ['name' => 'Hana']);
    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Till']);
    $session = app(ActingAsSession::class)->start($other, $device);

    $response = ($this->pay)($order, ['reference_no' => 'ABC-999', 'tendered_amount' => '340.00'], $f->cashier, null, ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint]);

    $response->assertCreated()->assertJsonPath('payment.received_by', $other->id);
    $payment = Payment::query()->sole();
    expect([$payment->received_by_user_id, $payment->acting_user_id, $payment->device_id])->toBe([$other->id, $other->id, $device->id]);
    $audit = AuditLog::query()->where('action', 'payment.record')->sole();
    expect([$audit->actor_id, $audit->acting_user_id, $audit->subject_id])->toBe([$f->cashier->id, $other->id, $payment->id])
        // the reference number is never logged (CLAUDE.md, the plan): not in the audit row, in any form
        ->and(json_encode([$audit->before, $audit->after, $audit->context]))->not->toContain('ABC-999');
    expect(json_encode($audit->after))->toContain('"amount":"340.00"');
})->group('module:payments', 'feature');

it('does not see another branch\'s order', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $elsewhere = Branch::factory()->create();
    $stranger = makeUser($elsewhere, 'cashier');

    ($this->pay)($order, [], $stranger)->assertNotFound();
    expect(($this->payments)())->toBe(0)->and(($this->row)($order['id'])->paid_total->amount())->toBe('0.00');
})->group('module:payments', 'feature');

it('dispatches PaymentRecorded once, inside the payment transaction, and rolls back with it', function () {
    $order = ($this->served)();
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(PaymentRecorded::class, function (PaymentRecorded $e) use (&$seen): void {
        $seen[] = [$e->payload(), DB::connection()->transactionLevel()];
    });

    ($this->pay)($order, ['amount' => '340.00'])->assertCreated();

    $payment = Payment::query()->sole();
    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBeGreaterThan($baseline)
        ->and($seen[0][0])->toBe([
            'payment_id' => $payment->id,
            'order_id' => $order['id'],
            'channel_code' => 'cash',
            'amount' => '340.00',
            'balance_after' => '0.00',
        ]);

    // a listener that fails takes the payment with it
    $second = ($this->floor)->send([$this->floor->menu->line($this->floor->menu->water)]);
    Event::listen(PaymentRecorded::class, function (): never {
        throw new RuntimeException('listener failed');
    });
    $this->withoutExceptionHandling();
    try {
        ($this->pay)($second);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('listener failed');
    }
    expect(($this->payments)())->toBe(1)->and(($this->row)($second['id'])->paid_total->amount())->toBe('0.00');
})->group('module:payments', 'feature');
