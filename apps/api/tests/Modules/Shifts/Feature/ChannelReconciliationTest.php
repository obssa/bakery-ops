<?php

declare(strict_types=1);

use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Shifts\Models\CashDeclaration;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Channel reconciliation inside the closing cash count (phase-3 plan section 10.5): for each channel that took money in the shift, or that
 * the cashier declared, expected_receipts / expected_sum come from Payments' Totals::byChannel and declared_receipts / declared_sum are what
 * the cashier counted from slips. difference_sum = declared - expected, only where something was declared; a non-zero difference needs the
 * same reason as a cash variance (one reason and note for the declaration). Until Phase 4 only cash can be recorded, so the table has one
 * row; the digital figures are accepted for enabled channels so the screen is ready. Rows are ordered by channel code.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $this->f->drawer($this->f->till);
    $this->cashier = $this->f->cashier;
    $opened = $this->f->openTill($this->f->till, $this->cashier, ['100' => 5]);
    $this->shift = $opened['shift'];
    $this->close = fn (): int => $this->f->closeTill($this->shift, $this->cashier)['count'];
    // a closing declaration that matches the drawer exactly, with the channel figures given
    $this->declare = fn (int $count, array $channels, array $extra = []) => $this->f->cashPut($this->cashier, $count, [
        'denominations' => ['100' => 5], 'float_left_total' => '100.00', 'channels' => $channels,
    ] + $extra);
});

it('lists a channel from what it took and from what was declared, with the difference, ordered by code', function () {
    $this->f->taken($this->shift, '75.00', null, 'telebirr');
    $this->f->taken($this->shift, '25.00', null, 'telebirr');
    $count = ($this->close)();

    $response = ($this->declare)($count, [
        ['code' => 'telebirr', 'receipts' => 2, 'sum' => '100.00'],
        ['code' => 'cbe_birr', 'receipts' => 1, 'sum' => '10.00'],
    ], ['reason_id' => $this->f->reasonId('miscount')])->assertOk();

    expect($response->json('cash.channels'))->toBe([
        ['code' => 'cbe_birr', 'name_en' => $response->json('cash.channels.0.name_en'), 'name_am' => $response->json('cash.channels.0.name_am'),
            'expected_receipts' => 0, 'expected_sum' => '0.00', 'declared_receipts' => 1, 'declared_sum' => '10.00', 'difference_sum' => '10.00'],
        ['code' => 'telebirr', 'name_en' => $response->json('cash.channels.1.name_en'), 'name_am' => $response->json('cash.channels.1.name_am'),
            'expected_receipts' => 2, 'expected_sum' => '100.00', 'declared_receipts' => 2, 'declared_sum' => '100.00', 'difference_sum' => '0.00'],
    ]);
    expect($response->json('cash.channels.0.name_en'))->toBeString()->not->toBe('')
        ->and($response->json('cash.channels.1.name_am'))->toBeString()->not->toBe('');
});

it('lists the cash row from the payments alone when nothing was declared, with no difference to explain', function () {
    $this->f->taken($this->shift, '500.00', '500.00');
    $count = ($this->close)();

    $response = $this->f->cashPut($this->cashier, $count, ['denominations' => ['100' => 10], 'float_left_total' => '100.00'])->assertOk();

    expect($response->json('cash.channels.0.code'))->toBe('cash')
        ->and($response->json('cash.channels.0.expected_receipts'))->toBe(1)
        ->and($response->json('cash.channels.0.expected_sum'))->toBe('500.00')
        ->and($response->json('cash.channels.0.declared_receipts'))->toBeNull()
        ->and($response->json('cash.channels.0.declared_sum'))->toBeNull()
        ->and($response->json('cash.channels.0.difference_sum'))->toBeNull();
    $this->f->submit($this->cashier, $count)->assertOk()->assertJsonPath('status', 'locked');
});

it('needs a reason for a channel difference even when the cash is right, and then waits for approval', function () {
    $this->f->taken($this->shift, '75.00', null, 'telebirr');
    $count = ($this->close)();
    ($this->declare)($count, [['code' => 'telebirr', 'receipts' => 1, 'sum' => '70.00']])->assertOk()
        ->assertJsonPath('cash.variance_total', '0.00')
        ->assertJsonPath('cash.channels.0.difference_sum', '-5.00');

    $this->f->submit($this->cashier, $count)->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.reason_required');

    ($this->declare)($count, [['code' => 'telebirr', 'receipts' => 1, 'sum' => '70.00']], ['reason_id' => $this->f->reasonId('miscount')])->assertOk();
    $this->f->submit($this->cashier, $count)->assertOk()->assertJsonPath('status', 'pending_approval');
});

it('does not ask for a reason when the declared figures agree to the cent', function () {
    $this->f->taken($this->shift, '0.10', null, 'telebirr');
    $this->f->taken($this->shift, '0.20', null, 'telebirr');
    $count = ($this->close)();

    ($this->declare)($count, [['code' => 'telebirr', 'receipts' => 2, 'sum' => '0.30']])->assertOk()
        ->assertJsonPath('cash.channels.0.expected_sum', '0.30')
        ->assertJsonPath('cash.channels.0.difference_sum', '0.00');
    $this->f->submit($this->cashier, $count)->assertOk()->assertJsonPath('status', 'locked');
});

it('leaves a channel that took money and was not declared without a difference', function () {
    $this->f->taken($this->shift, '75.00', null, 'telebirr');
    $count = ($this->close)();

    ($this->declare)($count, [])->assertOk()
        ->assertJsonPath('cash.channels.0.code', 'telebirr')
        ->assertJsonPath('cash.channels.0.expected_sum', '75.00')
        ->assertJsonPath('cash.channels.0.declared_sum', null)
        ->assertJsonPath('cash.channels.0.difference_sum', null);
    $this->f->submit($this->cashier, $count)->assertOk()->assertJsonPath('status', 'locked');
});

it('fixes the table at the submit: later payments do not move a handed-in count', function () {
    $this->f->taken($this->shift, '75.00', null, 'telebirr');
    $count = ($this->close)();
    ($this->declare)($count, [['code' => 'telebirr', 'receipts' => 1, 'sum' => '75.00']])->assertOk();
    $this->f->submit($this->cashier, $count)->assertOk();

    $this->f->taken($this->shift, '20.00', null, 'telebirr');

    $this->getJson("/api/v1/stock-counts/{$count}")->assertOk()
        ->assertJsonPath('cash.channels.0.expected_receipts', 1)
        ->assertJsonPath('cash.channels.0.expected_sum', '75.00');
    $stored = CashDeclaration::query()->where('count_id', $count)->sole()->channel_counts;
    expect($stored)->toBeArray()->and($stored[0]['code'])->toBe('telebirr')->and($stored[0]['expected_sum'])->toBe('75.00');
});

it('refuses a channel that is not the branch\'s, a switched-off one, a repeated one and a figure that is not a count of money', function (array $channels, string $key) {
    $count = ($this->close)();
    if ($key === 'disabled') {
        PaymentChannel::query()->where('code', 'cbe_birr')->update(['is_enabled' => false]);
        $key = 'shifts.cash.channel_unknown';
    }

    ($this->declare)($count, $channels)->assertUnprocessable()->assertJsonPath('message_key', $key);
})->with([
    'unknown' => [[['code' => 'bitcoin', 'receipts' => 1, 'sum' => '1.00']], 'shifts.cash.channel_unknown'],
    'switched off' => [[['code' => 'cbe_birr', 'receipts' => 1, 'sum' => '1.00']], 'disabled'],
    'repeated' => [[['code' => 'telebirr', 'receipts' => 1, 'sum' => '1.00'], ['code' => 'telebirr', 'receipts' => 1, 'sum' => '2.00']], 'shifts.cash.count_invalid'],
    'negative receipts' => [[['code' => 'telebirr', 'receipts' => -1, 'sum' => '1.00']], 'shifts.cash.count_invalid'],
    'a negative sum' => [[['code' => 'telebirr', 'receipts' => 1, 'sum' => '-1.00']], 'shifts.cash.count_invalid'],
    'three decimals' => [[['code' => 'telebirr', 'receipts' => 1, 'sum' => '1.005']], 'shifts.cash.count_invalid'],
    'not a number' => [[['code' => 'telebirr', 'receipts' => 1, 'sum' => 'lots']], 'shifts.cash.count_invalid'],
]);
