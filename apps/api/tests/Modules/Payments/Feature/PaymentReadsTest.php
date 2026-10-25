<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;
use Tests\Support\ReceiptFiles;

pest()->group('module:payments', 'feature');

/*
 * The payment reads of step 4.3b (phase-4 plan section 4.3; payments.view: admin, manager, cashier):
 *   GET /orders/{order}/payments  {data: [Payment], balance}, oldest first
 *   GET /payments/{payment}       Payment with its order and its refunds (none before step 4.3d)
 *   GET /payments                 {data: [Payment + order], next_cursor, server_time}, newest first; filters: local dates, channel, status,
 *                                 shift, order number, reference (normalised), missing photo, flagged (a duplicate reference or receipt, or
 *                                 disputed); cursor and limit (50, at most 200). Polled: PaymentsPollTest has the ETag.
 * A Payment carries its channel, the person who took it, the shift, and its receipt as {file_id, url, url_expires_at}: the URL is signed when
 * it is read (Files::signedUrl, 5 minutes), never stored. A cashier sees the payments of the last 24 hours and of their own shift (10.2 R-N);
 * a manager and an admin see all of the branch's.
 */

const PAYMENT_KEYS = [
    'id', 'order_id', 'client_uuid', 'channel', 'amount', 'tendered_amount', 'change_amount', 'currency', 'reference_no', 'status', 'received_at',
    'received_by', 'shift_id', 'batch_uuid', 'receipt', 'verified_at', 'disputed_at', 'dispute_reason', 'refunded_amount', 'void_reason', 'voided_at', 'flags',
];

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->floor = OrderFloor::open($this);
    $this->get = function (string $uri, ?User $as = null): TestResponse {
        loginAs($as ?? $this->floor->manager);

        return $this->getJson($uri);
    };
    $this->pay = function (array $order, array $body): TestResponse {
        loginAs($this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order['id']}/payments", $body + ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash']);
    };
    // two bottles of water, served: 60.00
    $this->waters = function (): array {
        $f = $this->floor;
        $order = $f->send([$f->menu->line($f->menu->water, '2')]);
        $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();

        return $order;
    };
    $this->channelId = fn (string $code): int => (int) PaymentChannel::query()->where('code', $code)->value('id');
    $this->tillShift = fn (): int => (int) DB::table('shift_instances')->where('station_id', $this->floor->menu->tillStation->id)->whereNotNull('open_key')->value('id');
});

it('lists an order\'s payments oldest first with the balance, the channel, the person, the shift and a signed receipt URL that shows the photo', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->water, '3')]); // 90.00, sent
    $file = ReceiptFiles::upload($this, $f->cashier);
    ($this->pay)($order, ['amount' => '20.00', 'tendered_amount' => '50.00'])->assertCreated();
    ($this->pay)($order, ['channel_code' => 'telebirr', 'amount' => '30.00', 'reference_no' => 'FT10203040', 'receipt_file_id' => $file])->assertCreated();

    $response = ($this->get)("/api/v1/orders/{$order['id']}/payments")->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'balance'])
        ->and($response->json('balance'))->toBe('40.00')
        ->and($response->json('data'))->toHaveCount(2)
        ->and(array_keys($response->json('data.0')))->toBe(PAYMENT_KEYS);
    $cash = $response->json('data.0');
    expect($cash)->toMatchArray([
        'order_id' => $order['id'], 'amount' => '20.00', 'tendered_amount' => '50.00', 'change_amount' => '30.00', 'currency' => 'ETB',
        'reference_no' => null, 'status' => 'recorded', 'shift_id' => ($this->tillShift)(), 'batch_uuid' => null, 'receipt' => null,
        'verified_at' => null, 'disputed_at' => null, 'dispute_reason' => null, 'refunded_amount' => '0.00', 'void_reason' => null, 'voided_at' => null,
        'flags' => ['duplicate_reference' => false, 'duplicate_receipt' => false],
    ])
        ->and($cash['channel'])->toBe(['id' => ($this->channelId)('cash'), 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ'])
        ->and($cash['received_by'])->toBe(['id' => $f->cashier->id, 'name' => 'Selam']);
    $wallet = $response->json('data.1');
    expect($wallet['channel']['code'])->toBe('telebirr')
        ->and($wallet['reference_no'])->toBe('FT10203040')
        ->and($wallet['tendered_amount'])->toBeNull()
        ->and(array_keys($wallet['receipt']))->toBe(['file_id', 'url', 'url_expires_at'])
        ->and($wallet['receipt']['file_id'])->toBe($file)
        ->and($wallet['receipt']['url'])->toContain('signature=')
        ->and(CarbonImmutable::parse($wallet['receipt']['url_expires_at'])->diffInSeconds(now(), true))->toBeLessThanOrEqual(301.0)
        ->and(json_encode($response->json(), JSON_UNESCAPED_SLASHES))->not->toContain('receipts/');

    $photo = $this->get($wallet['receipt']['url']);
    $photo->assertOk();
    expect($photo->headers->get('Content-Type'))->toBe('image/jpeg');

    // the cashier reads it too (the till); another branch's order is not found
    ($this->get)("/api/v1/orders/{$order['id']}/payments", $f->cashier)->assertOk()->assertJsonCount(2, 'data');
    $stranger = makeUser(Branch::factory()->create(), 'manager');
    ($this->get)("/api/v1/orders/{$order['id']}/payments", $stranger)->assertNotFound();
});

it('shows one payment with its order and its refunds, and not another branch\'s', function () {
    $f = $this->floor;
    $order = ($this->waters)();
    $id = ($this->pay)($order, ['amount' => '60.00'])->assertCreated()->json('payment.id');

    $response = ($this->get)("/api/v1/payments/{$id}")->assertOk();

    expect(array_keys($response->json()))->toBe([...PAYMENT_KEYS, 'order', 'refunds'])
        ->and($response->json('id'))->toBe($id)
        ->and($response->json('refunds'))->toBe([])
        ->and($response->json('order'))->toBe([
            'id' => $order['id'], 'order_no' => $order['order_no'], 'business_date' => $order['business_date'],
            'table_code' => $order['table_code'] ?? null, 'total' => '60.00',
        ]);
    ($this->get)('/api/v1/payments/987654')->assertNotFound();
    ($this->get)("/api/v1/payments/{$id}", makeUser(Branch::factory()->create(), 'manager'))->assertNotFound();
});

describe('the branch list', function () {
    beforeEach(function () {
        $f = $this->floor;
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));
        $sha = hash('sha256', 'the same photo');
        $order = fn (int $no): int => Order::factory()->sent($no)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->admin->id, 'total' => '90.00'])->id;
        $file = fn (string $hash): int => ReceiptFiles::row($f->branch->id, $f->cashier->id, ['sha256' => $hash, 'attached_type' => 'payment', 'attached_at' => now()])->id;
        $make = fn (array $attributes): Payment => Payment::factory()->create($attributes + [
            'branch_id' => $f->branch->id, 'received_by_user_id' => $f->cashier->id, 'tendered_amount' => null, 'change_amount' => null,
        ]);
        $this->shiftId = ($this->tillShift)();
        $this->p = [
            1 => $make(['order_id' => $order(11), 'channel_id' => ($this->channelId)('cash'), 'amount' => '10.00', 'tendered_amount' => '10.00', 'change_amount' => '0.00', 'received_at' => '2026-11-02 20:59:59']),
            2 => $make(['order_id' => $order(12), 'channel_id' => ($this->channelId)('telebirr'), 'amount' => '20.00', 'received_at' => '2026-11-02 21:00:00', 'reference_no' => 'FT-777', 'reference_norm' => 'FT777', 'receipt_file_id' => $file($sha), 'shift_instance_id' => $this->shiftId]),
            3 => $make(['order_id' => $order(13), 'channel_id' => ($this->channelId)('telebirr'), 'amount' => '30.00', 'received_at' => '2026-11-03 10:00:00', 'reference_no' => 'ft 777', 'reference_norm' => 'FT777', 'status' => 'disputed']),
            4 => $make(['order_id' => $order(14), 'channel_id' => ($this->channelId)('cbe_birr'), 'amount' => '40.00', 'received_at' => '2026-11-03 11:00:00', 'receipt_file_id' => $file($sha), 'status' => 'voided']),
            5 => $make(['order_id' => $order(15), 'channel_id' => ($this->channelId)('abyssinia'), 'amount' => '50.00', 'received_at' => '2026-11-03 12:00:00', 'receipt_file_id' => $file($sha)]),
        ];
        // another branch's payment is never listed
        $elsewhere = Branch::factory()->create();
        Payment::factory()->create(['branch_id' => $elsewhere->id, 'received_by_user_id' => makeUser($elsewhere, 'cashier')->id]);
        $this->ids = fn (TestResponse $r): array => array_map(fn (int $id): int => array_search($id, array_map(fn (Payment $p): int => $p->id, $this->p), true), $r->json('data.*.id'));
    });

    it('lists the branch\'s payments newest first with their order, and stamps the server time', function () {
        $response = ($this->get)('/api/v1/payments')->assertOk();

        expect(array_keys($response->json()))->toBe(['data', 'next_cursor', 'server_time'])
            ->and(($this->ids)($response))->toBe([5, 4, 3, 2, 1])
            ->and($response->json('next_cursor'))->toBeNull()
            ->and(CarbonImmutable::parse($response->json('server_time'))->equalTo(now()))->toBeTrue()
            ->and(array_keys($response->json('data.0')))->toBe([...PAYMENT_KEYS, 'order'])
            ->and($response->json('data.4.order'))->toMatchArray(['order_no' => 11, 'total' => '90.00'])
            ->and($response->json('data.4.order.id'))->toBe($this->p[1]->order_id);
    });

    it('pages with a cursor and a limit of at most 200', function () {
        $first = ($this->get)('/api/v1/payments?limit=2')->assertOk();
        expect(($this->ids)($first))->toBe([5, 4])->and($first->json('next_cursor'))->toBeString();
        $second = ($this->get)('/api/v1/payments?limit=2&cursor='.$first->json('next_cursor'))->assertOk();
        expect(($this->ids)($second))->toBe([3, 2]);
        $third = ($this->get)('/api/v1/payments?limit=2&cursor='.$second->json('next_cursor'))->assertOk();
        expect(($this->ids)($third))->toBe([1])->and($third->json('next_cursor'))->toBeNull();

        foreach (['limit=0', 'limit=201', 'cursor=abc', 'limit=x'] as $bad) {
            ($this->get)("/api/v1/payments?{$bad}")->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
        }
        ($this->get)('/api/v1/payments?limit=200')->assertOk()->assertJsonCount(5, 'data');
    });

    it('filters by local dates, channel, status, shift, order number, reference, missing photo and flagged', function () {
        $cases = [
            'from=2026-11-02&to=2026-11-02' => [1],              // 20:59:59Z is 23:59:59 in Addis Ababa
            'from=2026-11-03' => [5, 4, 3, 2],                    // 21:00:00Z is midnight of the 3rd
            'to=2026-11-02' => [1],
            'channel_id='.($this->channelId)('telebirr') => [3, 2],
            'status=disputed' => [3],
            'status=voided' => [4],
            'shift_id='.$this->shiftId => [2],
            'order_no=11' => [1],
            'reference=ft-7 77' => [3, 2],                        // normalised like the stored one
            'missing_photo=1' => [3],                             // a digital payment without a receipt
            'flagged=1' => [5, 3, 2],                             // 2 and 3 share a reference, 2 and 5 a photo, 3 is disputed; the voided 4 is no one's duplicate
            'channel_id='.($this->channelId)('telebirr').'&flagged=1&from=2026-11-03' => [3, 2],
        ];
        foreach ($cases as $query => $expected) {
            expect(($this->ids)(($this->get)("/api/v1/payments?{$query}")->assertOk()))->toBe($expected, $query);
        }
        foreach (['from=2026-13-01', 'to=yesterday', 'status=lost', 'channel_id=x', 'from=2026-11-04&to=2026-11-03'] as $bad) {
            ($this->get)("/api/v1/payments?{$bad}")->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
        }
    });

    it('says on each payment why it is flagged', function () {
        $flags = collect(($this->get)('/api/v1/payments')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row): array => [array_search($row['id'], array_map(fn (Payment $p): int => $p->id, $this->p), true) => $row['flags']])->all();

        expect($flags)->toBe([
            5 => ['duplicate_reference' => false, 'duplicate_receipt' => true],
            4 => ['duplicate_reference' => false, 'duplicate_receipt' => false],
            3 => ['duplicate_reference' => true, 'duplicate_receipt' => false],
            2 => ['duplicate_reference' => true, 'duplicate_receipt' => true],
            1 => ['duplicate_reference' => false, 'duplicate_receipt' => false],
        ]);
    });

    it('shows a cashier the last 24 hours and their own shift, and a manager and an admin everything', function () {
        $f = $this->floor;
        $recent = Payment::factory()->create([
            'branch_id' => $f->branch->id, 'received_by_user_id' => $f->cashier->id, 'received_at' => now()->subHours(23),
            'order_id' => Order::factory()->sent(16)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->admin->id])->id,
        ]);

        // the cashier carries the till's shift (payment 2 was taken in it) and sees what came in since yesterday at this time
        $mine = ($this->get)('/api/v1/payments', $f->cashier)->assertOk()->json('data.*.id');
        expect($mine)->toBe([$recent->id, $this->p[2]->id]);
        ($this->get)("/api/v1/payments/{$this->p[1]->id}", $f->cashier)->assertNotFound();
        ($this->get)("/api/v1/payments/{$this->p[2]->id}", $f->cashier)->assertOk();

        expect(($this->get)('/api/v1/payments', $f->manager)->json('data'))->toHaveCount(6)
            ->and(($this->get)('/api/v1/payments', $f->admin)->json('data'))->toHaveCount(6);
    });
});
