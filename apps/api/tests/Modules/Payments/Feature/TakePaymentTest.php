<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'feature');

/*
 * POST /orders/{order}/payments from step 4.3b (phase-4 plan section 4.3, "Taking payments"): every enabled channel. RecordPayment checks,
 * in this order, and answers each refusal in English and Amharic:
 *   1. the channel (payments.channel.unknown, .disabled);
 *   2. the receipt photo when the channel needs one (payments.channel.photo_required without a receipt_file_id; payments.photo.invalid
 *      for a file that is not this branch's, not a receipt, already attached, or older than payments.photo_max_age_hours = 12);
 *   3. the reference when the channel needs one (payments.channel.reference_required) and its pattern when one is set
 *      (payments.reference.invalid with the channel's hint pair);
 *   4. the shift rule, for every channel (10.1 R-A; PaymentRequiresShiftTest has the switch table);
 *   5. the order's state (payments.order.not_payable, .settled);
 *   6. 0 < amount <= balance: a part payment is taken, more than the balance is payments.amount.mismatch.
 * The payment row, the photo's attached_* and PaymentRecorded are one transaction. A reference used before on the channel, or a photo
 * whose bytes are on another payment, is a warning in the answer, never a refusal (10.8 R-B).
 * Prices of the OrdersMenu: sandwich 250.00, croissant 60.00, water 30.00.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $this->pay = function (array $order, array $body = [], ?User $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson(
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
    $this->cancelled = function (): array {
        $f = $this->floor;
        $order = $f->send([$f->menu->line($f->menu->water)], null, ['send' => false]);
        loginAs($f->waiter);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$order['id']}/cancel", [])->assertOk();

        return $order;
    };
    $this->photo = fn (?string $bytes = null, string $purpose = 'receipt'): int => ReceiptFiles::upload($this, $this->floor->cashier, $bytes, $purpose);
    // a Telebirr payment with its photo and reference
    $this->telebirr = fn (array $extra = []): array => $extra + ['channel_code' => 'telebirr', 'reference_no' => 'FT'.random_int(10000000, 99999999), 'receipt_file_id' => ($this->photo)()];
    $this->refused = function (TestResponse $response, int $status, string $key): TestResponse {
        $response->assertStatus($status)->assertJsonPath('message_key', $key);
        $en = $response->json('message_en');
        $am = $response->json('message_am');
        expect($en)->toBeString()->not->toBeEmpty()->not->toBe($key)->not->toBe('shared.'.$key)
            ->and($am)->toBeString()->toMatch('/\p{Ethiopic}/u')->not->toBe($en);

        return $response;
    };
    $this->row = fn (int $id): Order => Order::query()->findOrFail($id);
    $this->file = fn (int $id): StoredFile => StoredFile::query()->withTrashed()->findOrFail($id);
});

it('checks a payment in order: the channel, the photo, the reference, the shift, the order and then the amount, in both languages', function () {
    $f = $this->floor;
    $served = ($this->served)();
    $cancelled = ($this->cancelled)();
    $file = ($this->photo)();
    $proof = ($this->photo)(null, 'refund_proof');
    PaymentChannel::query()->where('code', 'telebirr')->update([
        'is_enabled' => false, 'reference_pattern' => 'FT\d{8}', 'reference_hint_en' => 'FT and eight digits', 'reference_hint_am' => 'FT እና ስምንት አሃዞች',
    ]);
    $f->assign($f->cashier); // the cashier works nowhere, so carries no shift
    $wrong = ['amount' => '999.00'];

    // everything is wrong: the channel is answered first
    ($this->refused)(($this->pay)($cancelled, ['channel_code' => 'barter'] + $wrong), 422, 'payments.channel.unknown');
    ($this->refused)(($this->pay)($cancelled, ['channel_code' => 'telebirr'] + $wrong), 422, 'payments.channel.disabled');
    PaymentChannel::query()->where('code', 'telebirr')->update(['is_enabled' => true]);
    // no photo and no reference: the photo comes first
    ($this->refused)(($this->pay)($cancelled, ['channel_code' => 'telebirr'] + $wrong), 422, 'payments.channel.photo_required')
        ->assertJsonPath('field', 'receipt_file_id')->assertJsonPath('channel_code', 'telebirr');
    ($this->refused)(($this->pay)($cancelled, ['channel_code' => 'telebirr', 'receipt_file_id' => $proof] + $wrong), 422, 'payments.photo.invalid')
        ->assertJsonPath('reason', 'wrong_purpose')->assertJsonPath('field', 'receipt_file_id');
    ($this->refused)(($this->pay)($cancelled, ['channel_code' => 'telebirr', 'receipt_file_id' => $file] + $wrong), 422, 'payments.channel.reference_required');
    ($this->refused)(($this->pay)($cancelled, ['channel_code' => 'telebirr', 'receipt_file_id' => $file, 'reference_no' => 'FT123'] + $wrong), 422, 'payments.reference.invalid')
        ->assertJsonPath('field', 'reference_no')
        ->assertJsonPath('hint_en', 'FT and eight digits')
        ->assertJsonPath('hint_am', 'FT እና ስምንት አሃዞች');
    $good = ['channel_code' => 'telebirr', 'receipt_file_id' => $file, 'reference_no' => 'FT12345678'];
    // a digital channel needs the till's shift too (R-A)
    ($this->refused)(($this->pay)($cancelled, $good + $wrong), 409, 'payments.shift.required');
    $f->assign($f->cashier, $f->menu->tillStation);
    ($this->refused)(($this->pay)($cancelled, $good + $wrong), 409, 'payments.order.not_payable')->assertJsonPath('status', 'cancelled');
    ($this->refused)(($this->pay)($served, $good + $wrong), 422, 'payments.amount.mismatch')
        ->assertJsonPath('balance', '340.00')->assertJsonPath('amount', '999.00');

    expect(Payment::query()->count())->toBe(0)
        ->and(($this->file)($file)->attached_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'payment.record')->count())->toBe(0);

    ($this->pay)($served, $good + ['amount' => '340.00'])->assertCreated()
        ->assertJsonPath('payment.channel_code', 'telebirr')
        ->assertJsonPath('payment.receipt_file_id', $file)
        ->assertJsonPath('payment.tendered_amount', null)
        ->assertJsonPath('payment.change_amount', null)
        ->assertJsonPath('order.status', 'closed')
        ->assertJsonPath('warnings', []);
});

it('refuses a photo that is unknown, another branch\'s, removed, not a receipt, attached already or older than 12 hours', function () {
    $f = $this->floor;
    $order = ($this->served)();
    $tele = fn (int $fileId): array => ['channel_code' => 'telebirr', 'reference_no' => 'FT'.random_int(10000000, 99999999), 'receipt_file_id' => $fileId, 'amount' => '10.00'];
    $invalid = fn (TestResponse $r, string $reason) => ($this->refused)($r, 422, 'payments.photo.invalid')->assertJsonPath('reason', $reason);

    $invalid(($this->pay)($order, $tele(987654)), 'not_found');

    $elsewhere = Branch::factory()->create();
    $theirs = ReceiptFiles::row($elsewhere->id, makeUser($elsewhere, 'cashier')->id);
    $invalid(($this->pay)($order, $tele($theirs->id)), 'not_found');

    $gone = ($this->photo)();
    StoredFile::query()->findOrFail($gone)->delete();
    $invalid(($this->pay)($order, $tele($gone)), 'not_found');

    $invalid(($this->pay)($order, $tele(($this->photo)(null, 'refund_proof'))), 'wrong_purpose');

    $used = ($this->photo)();
    ($this->pay)($order, $tele($used))->assertCreated();
    $invalid(($this->pay)($order, $tele($used)), 'attached');

    expect(config('payments.photo_max_age_hours'))->toBe(12);
    $fresh = ($this->photo)();
    $old = ($this->photo)();
    $this->travel(11 * 60 + 59)->minutes();
    ($this->pay)($order, $tele($fresh))->assertCreated();
    $this->travel(2)->minutes();
    $invalid(($this->pay)($order, $tele($old)), 'expired');

    expect(Payment::query()->count())->toBe(2)
        ->and(($this->file)($old)->attached_at)->toBeNull();
});

it('takes a photo on a channel that does not ask for one, and checks it the same way', function () {
    $order = ($this->served)();
    PaymentChannel::query()->where('code', 'cbe_bank')->update(['requires_photo' => false]);

    ($this->pay)($order, ['channel_code' => 'cbe_bank', 'reference_no' => 'TT-1', 'amount' => '40.00'])->assertCreated()->assertJsonPath('payment.receipt_file_id', null);
    ($this->refused)(($this->pay)($order, ['channel_code' => 'cbe_bank', 'reference_no' => 'TT-2', 'amount' => '40.00', 'receipt_file_id' => 424242]), 422, 'payments.photo.invalid');
    $file = ($this->photo)();
    ($this->pay)($order, ['channel_code' => 'cbe_bank', 'reference_no' => 'TT-3', 'amount' => '40.00', 'receipt_file_id' => $file])->assertCreated()
        ->assertJsonPath('payment.receipt_file_id', $file);
    expect(($this->file)($file)->attached_type)->toBe('payment');
});

it('checks the reference pattern only when the channel has one, and matches the whole reference', function () {
    $order = ($this->served)();
    PaymentChannel::query()->where('code', 'telebirr')->update(['reference_pattern' => '[A-Z0-9]{10}', 'reference_hint_en' => 'Ten letters or digits', 'reference_hint_am' => 'አሥር ፊደላት ወይም አሃዞች']);

    foreach (['ABCDE12345X', 'ABCDE1234', 'abcde12345', 'ABCDE 12345'] as $bad) {
        ($this->refused)(($this->pay)($order, ($this->telebirr)(['reference_no' => $bad, 'amount' => '10.00'])), 422, 'payments.reference.invalid');
    }
    ($this->pay)($order, ($this->telebirr)(['reference_no' => '  ABCDE12345 ', 'amount' => '10.00']))->assertCreated();
    expect(Payment::query()->sole()->reference_no)->toBe('ABCDE12345');

    // no pattern: any reference that is not blank, as in Phase 1
    ($this->pay)($order, ($this->telebirr)(['channel_code' => 'cbe_birr', 'reference_no' => 'whatever 1', 'amount' => '10.00']))->assertCreated();
    // a pattern the database holds but that would backtrack forever answers "no match" at once (ReferencePattern, step 4.3a)
    PaymentChannel::query()->where('code', 'awash')->update(['reference_pattern' => '(a+)+$']);
    ($this->refused)(($this->pay)($order, ($this->telebirr)(['channel_code' => 'awash', 'reference_no' => str_repeat('a', 40).'!', 'amount' => '10.00'])), 422, 'payments.reference.invalid')
        ->assertJsonPath('hint_en', null)->assertJsonPath('hint_am', null);
});

it('takes part payments until the balance is zero: the last one settles the order and the display-case lines are sold once', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->served)();
    $croissantRef = $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']);

    ($this->pay)($order, ['amount' => '100.00', 'tendered_amount' => '100.00'])->assertCreated()
        ->assertJsonPath('order.status', 'served')
        ->assertJsonPath('order.paid', '100.00')
        ->assertJsonPath('order.balance', '240.00');
    expect(($this->row)($order['id'])->settled_at)->toBeNull()
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([]);

    ($this->refused)(($this->pay)($order, ['amount' => '240.01', 'tendered_amount' => '300.00']), 422, 'payments.amount.mismatch')
        ->assertJsonPath('balance', '240.00')->assertJsonPath('amount', '240.01');

    ($this->pay)($order, ($this->telebirr)(['amount' => '140.00']))->assertCreated()
        ->assertJsonPath('order.status', 'served')
        ->assertJsonPath('order.balance', '100.00');

    ($this->pay)($order, ['amount' => '100.00', 'tendered_amount' => '200.00'])->assertCreated()
        ->assertJsonPath('payment.change_amount', '100.00')
        ->assertJsonPath('order.status', 'closed')
        ->assertJsonPath('order.paid', '340.00')
        ->assertJsonPath('order.balance', '0.00');

    $events = OrderEvent::query()->where('order_id', $order['id'])->pluck('event')->map(fn ($e) => $e->value)->all();
    expect(array_count_values($events)['order.settled'] ?? 0)->toBe(1)
        ->and(array_count_values($events)['order.closed'] ?? 0)->toBe(1)
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '-1.0000'])
        ->and(Payment::query()->where('order_id', $order['id'])->count())->toBe(3)
        ->and(($this->row)($order['id'])->paid_total->amount())->toBe('340.00');

    ($this->refused)(($this->pay)($order, ['amount' => '1.00']), 409, 'payments.order.not_payable')->assertJsonPath('status', 'closed');
});

it('takes a part payment before the food, and refuses another once the balance is settled', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->sandwich)]); // 250.00, sent

    ($this->pay)($order, ['amount' => '50.00'])->assertCreated()->assertJsonPath('order.status', 'sent')->assertJsonPath('order.balance', '200.00');
    ($this->pay)($order, ($this->telebirr)(['amount' => '200.00']))->assertCreated()->assertJsonPath('order.balance', '0.00');

    expect(($this->row)($order['id'])->settled_at)->not->toBeNull();
    ($this->refused)(($this->pay)($order, ['amount' => '1.00']), 409, 'payments.order.settled');
});

it('attaches the receipt photo to its payment in the payment transaction, and leaves it free when the payment rolls back', function () {
    $order = ($this->served)();
    $file = ($this->photo)();

    $response = ($this->pay)($order, ($this->telebirr)(['receipt_file_id' => $file, 'amount' => '100.00']))->assertCreated();

    $payment = Payment::query()->findOrFail($response->json('payment.id'));
    $row = ($this->file)($file);
    expect($row->attached_type)->toBe('payment')
        ->and($row->attached_id)->toBe($payment->id)
        ->and($row->attached_at)->not->toBeNull()
        ->and($payment->receipt_file_id)->toBe($file)
        // the Phase 1 column keeps its meaning: the object's key, signed on read
        ->and($payment->receipt_photo_key)->toBe($row->getAttribute('key'));

    $other = ($this->photo)();
    Event::listen(PaymentRecorded::class, function (): never {
        throw new RuntimeException('listener failed');
    });
    $this->withoutExceptionHandling();
    try {
        ($this->pay)($order, ($this->telebirr)(['receipt_file_id' => $other, 'amount' => '100.00']));
        $this->fail('the payment should have failed');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('listener failed');
    }
    $free = ($this->file)($other);
    expect([$free->attached_type, $free->attached_id, $free->attached_at])->toBe([null, null, null])
        ->and(Payment::query()->count())->toBe(1);
});

it('warns about a reference used before on the channel and a photo already on another payment, and takes the payment', function () {
    $bytes = imgJpeg(900, 700);
    $first = ($this->pay)(($this->served)(), ($this->telebirr)(['reference_no' => 'FT-1234 5678', 'receipt_file_id' => ($this->photo)($bytes)]))->assertCreated();
    expect($first->json('warnings'))->toBe([]);

    $second = ($this->pay)(($this->served)(), ($this->telebirr)(['reference_no' => 'ft12345678', 'receipt_file_id' => ($this->photo)($bytes)]))->assertCreated();

    $firstId = $first->json('payment.id');
    $warnings = $second->json('warnings');
    expect(array_column($warnings, 'code'))->toBe(['reference.duplicate', 'receipt.duplicate'])
        ->and($warnings[0]['payment_ids'])->toBe([$firstId])
        ->and($warnings[1]['payment_ids'])->toBe([$firstId])
        ->and($warnings[0]['payment_id'])->toBe($second->json('payment.id'));
    foreach ($warnings as $warning) {
        expect($warning['message_key'])->toStartWith('payments.warning.')
            ->and($warning['message_en'])->toBeString()->not->toBeEmpty()->not->toBe($warning['message_key'])
            ->and($warning['message_am'])->toMatch('/\p{Ethiopic}/u');
    }

    // the same reference on another channel is no duplicate; nor is one on a voided payment
    $elsewhere = ($this->pay)(($this->served)(), ($this->telebirr)(['channel_code' => 'cbe_birr', 'reference_no' => 'FT12345678']))->assertCreated();
    expect($elsewhere->json('warnings'))->toBe([]);
    Payment::query()->whereKey($firstId)->update(['status' => 'voided']);
    $third = ($this->pay)(($this->served)(), ($this->telebirr)(['reference_no' => 'FT 1234-5678']))->assertCreated();
    expect(array_column($third->json('warnings'), 'payment_ids'))->toBe([[$second->json('payment.id')]]);
});

it('answers the warnings again when a payment is answered again for its client_uuid', function () {
    $ref = 'FT87654321';
    ($this->pay)(($this->served)(), ($this->telebirr)(['reference_no' => $ref]))->assertCreated();
    $order = ($this->served)();
    $body = ($this->telebirr)(['reference_no' => $ref, 'client_uuid' => (string) Str::uuid()]);

    $taken = ($this->pay)($order, $body)->assertCreated();
    $again = ($this->pay)($order, $body)->assertOk();

    expect($again->json('payment.id'))->toBe($taken->json('payment.id'))
        ->and($again->json('warnings'))->toBe($taken->json('warnings'))
        ->and(array_column($again->json('warnings'), 'code'))->toBe(['reference.duplicate']);
});

it('stores the normalised reference and keeps it, the reference and the photo key out of the audit row', function () {
    $order = ($this->served)();
    $file = ($this->photo)();

    ($this->pay)($order, ($this->telebirr)(['reference_no' => 'ft-2024 0099', 'receipt_file_id' => $file]))->assertCreated();

    $payment = Payment::query()->sole();
    $key = ($this->file)($file)->getAttribute('key');
    expect($payment->reference_no)->toBe('ft-2024 0099')
        ->and($payment->reference_norm)->toBe('FT20240099')
        ->and($payment->batch_uuid)->toBeNull()
        ->and($payment->refunded_amount->amount())->toBe('0.00');
    $audit = AuditLog::query()->where('action', 'payment.record')->sole();
    $logged = (string) json_encode([$audit->before, $audit->after, $audit->context], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect($key)->toStartWith('receipts/')
        ->and($logged)->not->toContain('ft-2024 0099')
        ->and($logged)->not->toContain('FT20240099')
        ->and($logged)->not->toContain($key)
        ->and($audit->after['receipt_photo_key'] ?? null)->toBe('[redacted]')
        ->and($audit->after['reference_norm'] ?? null)->toBe('[redacted]')
        ->and($audit->after['receipt_file_id'] ?? null)->toBe($file);
    expect(DB::table('audit_log')->where('action', 'payment.record')->count())->toBe(1);
});
