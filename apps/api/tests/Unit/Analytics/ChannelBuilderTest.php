<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\ChannelBuilder;
use Tests\Support\AnalyticsFacts as F;

/*
 * daily_channel_totals (phase-4 plan M14): per channel, the day's live payments (received_at; a disputed payment still counts, 10.8 R-Q)
 * and the day's refunds (refunded_at). A channel that only paid money back that day has a negative net. without_reference and
 * without_photo count literally, as Payments' reconciliation does (a cash payment has neither); duplicate_reference is the port's flag.
 */

it('totals each channel: receipts, money in, money back, net, and the flags', function () {
    $rows = (new ChannelBuilder)->build(
        F::DAY,
        [
            F::payment(['payment_id' => 1, 'channel_id' => 1, 'amount' => '100.00']),
            F::payment(['payment_id' => 2, 'channel_id' => 1, 'amount' => '50.00']),
            F::payment(['payment_id' => 3, 'channel_id' => 2, 'amount' => '200.00', 'status' => 'verified', 'has_reference' => true, 'has_receipt' => true, 'duplicate_reference' => true]),
            F::payment(['payment_id' => 4, 'channel_id' => 2, 'amount' => '80.00', 'status' => 'disputed', 'has_reference' => true, 'duplicate_reference' => true]),
            F::payment(['payment_id' => 5, 'channel_id' => 3, 'amount' => '30.00', 'has_receipt' => true]),
        ],
        [
            F::refund(['refund_id' => 1, 'channel_id' => 2, 'amount' => '20.00']),
            F::refund(['refund_id' => 2, 'channel_id' => 4, 'amount' => '15.00', 'order_refund_id' => 2]),
        ],
    );

    $zero = ['refund_count' => 0, 'refund_amount' => '0.00'];
    expect($rows)->toBe([
        ['day' => F::DAY, 'channel_id' => 1, 'receipts_count' => 2, 'amount' => '150.00', 'refund_count' => 0, 'refund_amount' => '0.00', 'net_amount' => '150.00',
            'without_reference_count' => 2, 'without_photo_count' => 2, 'verified_count' => 0, 'disputed_count' => 0, 'duplicate_reference_count' => 0],
        ['day' => F::DAY, 'channel_id' => 2, 'receipts_count' => 2, 'amount' => '280.00', 'refund_count' => 1, 'refund_amount' => '20.00', 'net_amount' => '260.00',
            'without_reference_count' => 0, 'without_photo_count' => 1, 'verified_count' => 1, 'disputed_count' => 1, 'duplicate_reference_count' => 2],
        ['day' => F::DAY, 'channel_id' => 3, 'receipts_count' => 1, 'amount' => '30.00'] + $zero + ['net_amount' => '30.00',
            'without_reference_count' => 1, 'without_photo_count' => 0, 'verified_count' => 0, 'disputed_count' => 0, 'duplicate_reference_count' => 0],
        ['day' => F::DAY, 'channel_id' => 4, 'receipts_count' => 0, 'amount' => '0.00', 'refund_count' => 1, 'refund_amount' => '15.00', 'net_amount' => '-15.00',
            'without_reference_count' => 0, 'without_photo_count' => 0, 'verified_count' => 0, 'disputed_count' => 0, 'duplicate_reference_count' => 0],
    ]);
})->group('unit', 'module:analytics');

it('has no row for a day without payments or refunds', function () {
    expect((new ChannelBuilder)->build(F::DAY, [], []))->toBe([]);
})->group('unit', 'module:analytics');
