<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Files\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'feature');

/*
 * POST /orders/{order}/payment-batches (phase-4 plan section 4.3, step 4.3b; payments.take): one bill split over channels in ONE action.
 * 1 to 6 rows, each a payment as POST /orders/{order}/payments takes it; their amounts must add up to exactly the balance
 * (422 payments.batch.sum_mismatch {balance, sum}); a row that would be refused on its own refuses the whole batch with
 * 422 payments.batch.row_invalid {row, error: the row's own refusal}; refusals about the till or the order (409 payments.shift.required,
 * payments.order.*) are answered as themselves. All rows or none: one AuditedAction (payment.batch), one transaction, one batch_uuid
 * (the batch's client_uuid) on every row. The batch's client_uuid answers the same batch again.
 * The served order of these tests: a sandwich, a croissant and a bottle of water = 340.00.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
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
    $this->batch = function (array $order, array $rows, array $extra = [], ?User $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson(
            "/api/v1/orders/{$order['id']}/payment-batches",
            $extra + ['client_uuid' => (string) Str::uuid(), 'rows' => array_map(fn (array $row): array => $row + ['client_uuid' => (string) Str::uuid()], $rows)],
        );
    };
    $this->photo = fn (?string $bytes = null): int => ReceiptFiles::upload($this, $this->floor->cashier, $bytes);
    $this->wallet = fn (string $amount, array $extra = []): array => $extra + [
        'channel_code' => 'telebirr', 'amount' => $amount, 'reference_no' => 'FT'.random_int(10000000, 99999999), 'receipt_file_id' => ($this->photo)(),
    ];
    $this->cash = fn (string $amount, ?string $tendered = null): array => ['channel_code' => 'cash', 'amount' => $amount] + ($tendered === null ? [] : ['tendered_amount' => $tendered]);
    $this->bilingual = function (array $error, string $key): void {
        expect($error['message_key'] ?? null)->toBe($key)
            ->and($error['message_en'] ?? null)->toBeString()->not->toBeEmpty()->not->toBe($key)
            ->and($error['message_am'] ?? null)->toBeString()->toMatch('/\p{Ethiopic}/u');
    };
    $this->nothingWritten = function (array $order, array $fileIds = []): void {
        expect(Payment::query()->where('order_id', $order['id'])->count())->toBe(0)
            ->and(Order::query()->findOrFail($order['id'])->paid_total->amount())->toBe('0.00')
            ->and(AuditLog::query()->where('action', 'payment.batch')->get()->filter(fn (AuditLog $a): bool => ($a->context['order_id'] ?? null) === $order['id'])->count())->toBe(0);
        foreach ($fileIds as $id) {
            expect(StoredFile::query()->findOrFail($id)->attached_at)->toBeNull("file {$id} stays free");
        }
    };
});

it('splits a bill over a wallet with its photo and cash with change: every row, one batch, one audit row, the order closed', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->served)();
    $croissantRef = $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']);
    $wallet = ($this->wallet)('200.00');
    $batchUuid = (string) Str::uuid();

    $response = ($this->batch)($order, [$wallet, ($this->cash)('140.00', '200.00')], ['client_uuid' => $batchUuid])->assertCreated();

    expect(array_keys($response->json()))->toBe(['payments', 'order', 'warnings'])
        ->and($response->json('payments.*.channel_code'))->toBe(['telebirr', 'cash'])
        ->and($response->json('payments.*.amount'))->toBe(['200.00', '140.00'])
        ->and($response->json('payments.*.batch_uuid'))->toBe([$batchUuid, $batchUuid])
        ->and($response->json('payments.0.receipt_file_id'))->toBe($wallet['receipt_file_id'])
        ->and($response->json('payments.1.change_amount'))->toBe('60.00')
        ->and($response->json('order'))->toMatchArray(['status' => 'closed', 'paid' => '340.00', 'balance' => '0.00'])
        ->and($response->json('warnings'))->toBe([]);

    $payments = Payment::query()->where('order_id', $order['id'])->orderBy('id')->get();
    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('batch_uuid')->unique()->all())->toBe([$batchUuid])
        ->and($payments->pluck('shift_instance_id')->filter()->count())->toBe(2)
        ->and(StoredFile::query()->findOrFail($wallet['receipt_file_id'])->attached_id)->toBe($payments[0]->id)
        ->and(AuditLog::query()->where('action', 'payment.batch')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payment.record')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payment.batch')->sole()->context['batch_uuid'] ?? null)->toBe($batchUuid)
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '-1.0000']);
    $events = OrderEvent::query()->where('order_id', $order['id'])->pluck('event')->map(fn ($e) => $e->value)->all();
    expect(array_count_values($events)['order.settled'] ?? 0)->toBe(1);
});

it('refuses a batch whose amounts do not add up to the balance, writing nothing', function () {
    $order = ($this->served)();

    foreach ([['200.00', '139.99', '339.99'], ['200.00', '140.01', '340.01']] as [$a, $b, $sum]) {
        $wallet = ($this->wallet)($a);
        $response = ($this->batch)($order, [$wallet, ($this->cash)($b)])->assertStatus(422);
        ($this->bilingual)($response->json(), 'payments.batch.sum_mismatch');
        $response->assertJsonPath('balance', '340.00')->assertJsonPath('sum', $sum);
        ($this->nothingWritten)($order, [$wallet['receipt_file_id']]);
    }
});

it('refuses the whole batch when one row would be refused, naming the row and its own refusal in both languages', function () {
    $order = ($this->served)();
    PaymentChannel::query()->where('code', 'cbe_birr')->update(['reference_pattern' => '\d{6}', 'reference_hint_en' => 'Six digits', 'reference_hint_am' => 'ስድስት አሃዞች']);
    $shared = ($this->photo)();

    $cases = [
        'unknown channel' => [[($this->wallet)('300.00'), ['channel_code' => 'barter', 'amount' => '40.00']], 1, 'payments.channel.unknown'],
        'no photo' => [[($this->cash)('40.00'), ['channel_code' => 'telebirr', 'amount' => '300.00', 'reference_no' => 'FT1']], 1, 'payments.channel.photo_required'],
        'no reference' => [[($this->wallet)('300.00', ['reference_no' => null]), ($this->cash)('40.00')], 0, 'payments.channel.reference_required'],
        'reference pattern' => [[($this->cash)('40.00'), ($this->wallet)('300.00', ['channel_code' => 'cbe_birr', 'reference_no' => '12345'])], 1, 'payments.reference.invalid'],
        'tendered too low' => [[($this->wallet)('300.00'), ($this->cash)('40.00', '39.00')], 1, 'payments.tendered.too_low'],
        'one photo on two rows' => [[($this->wallet)('300.00', ['receipt_file_id' => $shared]), ($this->wallet)('40.00', ['receipt_file_id' => $shared])], 1, 'payments.photo.invalid'],
    ];
    foreach ($cases as $name => [$rows, $row, $key]) {
        $response = ($this->batch)($order, $rows)->assertStatus(422);
        ($this->bilingual)($response->json(), 'payments.batch.row_invalid');
        expect($response->json('row'))->toBe($row, $name);
        ($this->bilingual)($response->json('error'), $key);
        ($this->nothingWritten)($order, array_values(array_filter(array_column($rows, 'receipt_file_id'))));
    }
    $response = ($this->batch)($order, [($this->cash)('40.00'), ($this->wallet)('300.00', ['channel_code' => 'cbe_birr', 'reference_no' => '12345'])]);
    $response->assertJsonPath('error.hint_en', 'Six digits')->assertJsonPath('error.hint_am', 'ስድስት አሃዞች');
});

it('takes 1 to 6 rows with distinct client ids', function () {
    $order = ($this->served)();
    $five = array_fill(0, 5, ($this->cash)('50.00'));

    ($this->batch)($order, [])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->batch)($order, [...$five, ($this->cash)('45.00'), ($this->cash)('45.00')])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $uuid = (string) Str::uuid();
    ($this->batch)($order, [['client_uuid' => $uuid] + ($this->cash)('170.00'), ['client_uuid' => $uuid] + ($this->cash)('170.00')])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->batch)($order, [($this->cash)('0.00'), ($this->cash)('340.00')])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->nothingWritten)($order);

    ($this->batch)($order, [...$five, ($this->cash)('90.00')])->assertCreated()->assertJsonCount(6, 'payments')->assertJsonPath('order.status', 'closed');

    $single = ($this->served)();
    ($this->batch)($single, [($this->wallet)('340.00')])->assertCreated()->assertJsonCount(1, 'payments');
});

it('answers the same batch again for its client_uuid under a new key and writes nothing; a client_uuid of another payment is refused', function () {
    $order = ($this->served)();
    $body = ['client_uuid' => (string) Str::uuid()];
    $rows = [($this->wallet)('300.00') + ['client_uuid' => (string) Str::uuid()], ($this->cash)('40.00') + ['client_uuid' => (string) Str::uuid()]];

    $first = ($this->batch)($order, $rows, $body)->assertCreated();
    $before = [Payment::query()->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $again = ($this->batch)($order, $rows, $body)->assertOk();

    expect($again->json('payments.*.id'))->toBe($first->json('payments.*.id'))
        ->and($again->json('order'))->toBe($first->json('order'))
        ->and([Payment::query()->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($before);

    // the batch's id names one order
    $other = ($this->served)();
    ($this->batch)($other, [($this->cash)('340.00')], $body)->assertStatus(409)->assertJsonPath('message_key', 'payments.client_uuid.reused');
    // a row may not reuse the id of a payment taken before
    ($this->batch)($other, [($this->cash)('340.00') + ['client_uuid' => $rows[1]['client_uuid']]])
        ->assertStatus(409)->assertJsonPath('message_key', 'payments.client_uuid.reused');
    ($this->nothingWritten)($other);
});

it('answers the order\'s and the till\'s refusals as themselves', function () {
    $f = $this->floor;
    $open = $f->send([$f->menu->line($f->menu->water)], null, ['send' => false]);
    ($this->batch)($open, [($this->cash)('30.00')])->assertStatus(409)->assertJsonPath('message_key', 'payments.order.not_payable')->assertJsonPath('status', 'open');

    $sent = $f->send([$f->menu->line($f->menu->sandwich)]);
    ($this->batch)($sent, [($this->cash)('250.00')])->assertCreated()->assertJsonPath('order.status', 'sent');
    ($this->batch)($sent, [($this->cash)('1.00')])->assertStatus(409)->assertJsonPath('message_key', 'payments.order.settled');

    $served = ($this->served)();
    $wallet = ($this->wallet)('300.00');
    $f->assign($f->cashier);
    ($this->batch)($served, [$wallet, ($this->cash)('40.00')])->assertStatus(409)->assertJsonPath('message_key', 'payments.shift.required');
    ($this->nothingWritten)($served, [$wallet['receipt_file_id']]);
});

it('warns when rows share a reference or a photo\'s bytes, and takes them', function () {
    $order = ($this->served)();
    $bytes = imgJpeg(820, 610);

    $response = ($this->batch)($order, [
        ($this->wallet)('300.00', ['reference_no' => 'FT55554444', 'receipt_file_id' => ($this->photo)($bytes)]),
        ($this->wallet)('40.00', ['reference_no' => 'ft-5555-4444', 'receipt_file_id' => ($this->photo)($bytes)]),
    ])->assertCreated();

    [$a, $b] = $response->json('payments.*.id');
    $warnings = collect($response->json('warnings'))->map(fn (array $w): array => [$w['code'], $w['payment_id'], $w['payment_ids']])->all();
    expect($warnings)->toBe([
        ['reference.duplicate', $a, [$b]],
        ['receipt.duplicate', $a, [$b]],
        ['reference.duplicate', $b, [$a]],
        ['receipt.duplicate', $b, [$a]],
    ]);
});
