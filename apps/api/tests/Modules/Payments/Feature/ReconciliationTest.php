<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\ReceiptFiles;
use Tests\Support\Receipts;

/*
 * GET /payments/reconciliation (phase-4 plan section 4.3, step 4.3e; payments.verify: admin, manager): the receipts of each local day and
 * channel against the bank's statement, computed from Payments' own tables (payments by received_at, payment_refunds by refunded_at, both
 * bucketed by the branch's local day, Africa/Addis_Ababa: 23:59:59 local is still that day, 00:00:00 the next). Per channel: receipts and
 * amount (the payments that are not voided; a disputed one still counts, 10.8 R-Q), with and without a photo, with a reference, verified,
 * disputed, voided (a count), refunds and refund_amount (the refunds paid back through that channel that day, whatever day the payment
 * was), net_amount = amount - refund_amount. Every day of the range is listed, oldest first, with the channels that had anything, in the
 * admin's channel order; totals sum the range per channel and overall. ?from=&to= local dates (the last 7 days by default, at most 62),
 * ?channel_id=. Polled: the ETag is Payments::fingerprint() with the refunds' own and the query, 304 once it settles.
 * Refunds come from factory rows (Tests\Support\Receipts::refund): nothing writes payment_refunds before step 4.3d.
 */

pest()->group('module:payments', 'feature');

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->manager = makeUser($this->branch, 'manager', ['name' => 'Meron']);
    withActor($this->manager->id);
    $this->channel = fn (string $code): PaymentChannel => PaymentChannel::query()->where('code', $code)->firstOrFail();
    // local Addis Ababa time -> a payment received then
    $this->payment = function (string $code, string $amount, string $localAt, array $extra = []): Payment {
        return Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'channel_id' => ($this->channel)($code)->id,
            'amount' => $amount,
            'tendered_amount' => $code === 'cash' ? $amount : null,
            'received_by_user_id' => $this->manager->id,
            'received_at' => CarbonImmutable::parse($localAt, 'Africa/Addis_Ababa')->setTimezone('UTC'),
        ] + $extra);
    };
    $this->photo = fn (): int => ReceiptFiles::row($this->branch->id, $this->manager->id)->id;
    $this->get = function (string $query = '', ?User $as = null, ?string $etag = null): TestResponse {
        loginAs($as ?? $this->manager);

        return $this->withHeaders($etag === null ? [] : ['If-None-Match' => $etag])->getJson('/api/v1/payments/reconciliation'.($query === '' ? '' : '?'.$query));
    };
    $this->row = fn (array $day, string $code): ?array => collect($day['channels'])->firstWhere('channel.code', $code);
});

it('counts each local day and channel: receipts, amount, photos, references, verified, disputed, voided, refunds and net', function () {
    $cash = ($this->payment)('cash', '100.00', '2026-10-20 08:00:00');
    ($this->payment)('cash', '50.00', '2026-10-20 09:00:00', ['status' => 'voided', 'voided_at' => now(), 'voided_by_user_id' => $this->manager->id, 'void_reason' => 'x']);
    ($this->payment)('telebirr', '70.00', '2026-10-20 10:00:00', ['status' => 'verified', 'reference_no' => 'TB1', 'receipt_file_id' => ($this->photo)(), 'verified_at' => now(), 'verified_by_user_id' => $this->manager->id]);
    ($this->payment)('telebirr', '30.00', '2026-10-20 11:00:00', ['status' => 'disputed', 'reference_no' => 'TB2', 'disputed_at' => now(), 'disputed_by_user_id' => $this->manager->id, 'dispute_reason' => 'not on the statement']);
    ($this->payment)('telebirr', '15.00', '2026-10-20 23:59:59', ['reference_no' => 'TB3', 'receipt_file_id' => ($this->photo)()]);
    ($this->payment)('awash', '40.00', '2026-10-20 12:00:00', ['receipt_file_id' => ($this->photo)()]);
    ($this->payment)('cash', '25.00', '2026-10-21 00:00:00');
    Receipts::refund($cash, '20.00', CarbonImmutable::parse('2026-10-21 10:00:00', 'Africa/Addis_Ababa'), $this->manager->id);

    $response = ($this->get)('from=2026-10-20&to=2026-10-22')->assertOk();

    $days = $response->json('days');
    expect(array_column($days, 'day'))->toBe(['2026-10-20', '2026-10-21', '2026-10-22'])
        ->and(array_map(fn (array $c): string => $c['channel']['code'], $days[0]['channels']))->toBe(['cash', 'telebirr', 'awash'])
        ->and($days[2]['channels'])->toBe([]);
    expect(($this->row)($days[0], 'cash'))->toBe([
        'channel' => ['id' => ($this->channel)('cash')->id, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ'],
        'receipts' => 1, 'amount' => '100.00', 'with_photo' => 0, 'without_photo' => 1, 'with_reference' => 0,
        'verified' => 0, 'disputed' => 0, 'voided' => 1, 'refunds' => 0, 'refund_amount' => '0.00', 'net_amount' => '100.00',
    ]);
    expect(($this->row)($days[0], 'telebirr'))->toMatchArray([
        'receipts' => 3, 'amount' => '115.00', 'with_photo' => 2, 'without_photo' => 1, 'with_reference' => 3,
        'verified' => 1, 'disputed' => 1, 'voided' => 0, 'refunds' => 0, 'refund_amount' => '0.00', 'net_amount' => '115.00',
    ]);
    expect(($this->row)($days[0], 'awash'))->toMatchArray(['receipts' => 1, 'amount' => '40.00', 'with_photo' => 1, 'without_photo' => 0, 'with_reference' => 0]);
    expect(($this->row)($days[1], 'cash'))->toMatchArray([
        'receipts' => 1, 'amount' => '25.00', 'refunds' => 1, 'refund_amount' => '20.00', 'net_amount' => '5.00',
    ]);
    expect($response->json('totals'))->toMatchArray([
        'receipts' => 6, 'amount' => '280.00', 'with_photo' => 3, 'without_photo' => 3, 'with_reference' => 3,
        'verified' => 1, 'disputed' => 1, 'voided' => 1, 'refunds' => 1, 'refund_amount' => '20.00', 'net_amount' => '260.00',
    ])
        ->and(collect($response->json('totals.channels'))->firstWhere('channel.code', 'cash'))->toMatchArray([
            'receipts' => 2, 'amount' => '125.00', 'voided' => 1, 'refunds' => 1, 'refund_amount' => '20.00', 'net_amount' => '105.00',
        ])
        ->and($response->json('from'))->toBe('2026-10-20')->and($response->json('to'))->toBe('2026-10-22')
        ->and($response->json('timezone'))->toBe('Africa/Addis_Ababa');
});

it('lists a channel that only paid money back that day', function () {
    $telebirr = ($this->payment)('telebirr', '60.00', '2026-10-20 10:00:00', ['reference_no' => 'TB9']);
    Receipts::refund($telebirr, '60.00', CarbonImmutable::parse('2026-10-22 09:00:00', 'Africa/Addis_Ababa'), $this->manager->id);

    $days = ($this->get)('from=2026-10-22&to=2026-10-22')->assertOk()->json('days');

    expect($days[0]['channels'])->toHaveCount(1)
        ->and($days[0]['channels'][0])->toMatchArray(['receipts' => 0, 'amount' => '0.00', 'refunds' => 1, 'refund_amount' => '60.00', 'net_amount' => '-60.00']);
});

it('filters to one channel', function () {
    ($this->payment)('cash', '100.00', '2026-10-20 08:00:00');
    ($this->payment)('telebirr', '70.00', '2026-10-20 10:00:00', ['reference_no' => 'TB1']);

    $response = ($this->get)('from=2026-10-20&to=2026-10-20&channel_id='.($this->channel)('telebirr')->id)->assertOk();

    expect(array_map(fn (array $c): string => $c['channel']['code'], $response->json('days.0.channels')))->toBe(['telebirr'])
        ->and($response->json('totals.amount'))->toBe('70.00');
});

it('reads the last seven local days by default, today included', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 20:59:59', 'UTC')); // 23:59:59 in Addis Ababa
    ($this->payment)('cash', '10.00', '2026-10-26 23:00:00');
    ($this->payment)('cash', '99.00', '2026-10-19 23:00:00');

    $response = ($this->get)()->assertOk();

    expect($response->json('from'))->toBe('2026-10-20')->and($response->json('to'))->toBe('2026-10-26')
        ->and($response->json('days'))->toHaveCount(7)
        ->and($response->json('totals.amount'))->toBe('10.00');
});

it('takes at most 62 days, and the range in order', function () {
    ($this->get)('from=2026-08-26&to=2026-10-26')->assertOk()->assertJsonCount(62, 'days');
    ($this->get)('from=2026-08-25&to=2026-10-26')->assertStatus(422)
        ->assertJsonPath('message_key', 'payments.reconciliation.range_too_large')->assertJsonPath('max_days', 62)
        ->assertJsonStructure(['message_en', 'message_am']);
    ($this->get)('from=2026-10-26&to=2026-10-20')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->get)('from=26-10-2026')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->get)('channel_id=x')->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
});

it('keeps another branch\'s payments and refunds out', function () {
    ($this->payment)('cash', '10.00', '2026-10-20 08:00:00');
    $other = Branch::factory()->create();
    $theirs = Payment::factory()->create(['branch_id' => $other->id, 'amount' => '500.00', 'received_by_user_id' => makeUser($other, 'cashier')->id,
        'received_at' => CarbonImmutable::parse('2026-10-20 09:00:00', 'Africa/Addis_Ababa')->setTimezone('UTC')]);
    Receipts::refund($theirs, '100.00', CarbonImmutable::parse('2026-10-20 10:00:00', 'Africa/Addis_Ababa'), $theirs->received_by_user_id);

    $response = ($this->get)('from=2026-10-20&to=2026-10-20')->assertOk();

    expect($response->json('totals.amount'))->toBe('10.00')->and($response->json('totals.refund_amount'))->toBe('0.00');
});

it('answers 304 once settled, and moves its tag when a payment or a refund comes in', function () {
    $cash = ($this->payment)('cash', '10.00', '2026-10-20 08:00:00');
    $settle = fn () => $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();
    $settle();
    $first = ($this->get)('from=2026-10-20&to=2026-10-21')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = (string) $first->headers->get('ETag');
    expect($etag)->toStartWith('"payments-reconciliation-');
    ($this->get)('from=2026-10-20&to=2026-10-21', null, $etag)->assertStatus(304);
    ($this->get)('from=2026-10-20&to=2026-10-20', null, $etag)->assertOk();

    Receipts::refund($cash, '5.00', CarbonImmutable::parse('2026-10-21 09:00:00', 'Africa/Addis_Ababa'), $this->manager->id);
    $afterRefund = ($this->get)('from=2026-10-20&to=2026-10-21', null, $etag)->assertOk();
    expect($afterRefund->json('totals.refund_amount'))->toBe('5.00');

    $settle();
    $settled = (string) ($this->get)('from=2026-10-20&to=2026-10-21')->headers->get('ETag');
    ($this->payment)('telebirr', '20.00', '2026-10-21 08:00:00', ['reference_no' => 'TB5']);
    expect((string) ($this->get)('from=2026-10-20&to=2026-10-21')->headers->get('ETag'))->not->toBe($settled);
});

it('is for the payment checkers only (payments.verify)', function (string $role) {
    ($this->get)('', makeUser($this->branch, $role))->assertForbidden();
})->with(['cashier', 'waiter']);
