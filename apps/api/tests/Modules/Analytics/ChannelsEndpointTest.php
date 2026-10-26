<?php

declare(strict_types=1);

use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Live\OverlapCursor;
use Tests\Support\Dashboards;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/channels?group=day|week|month (phase-4 plan section 4.6, step 4.4c; analytics.view). daily_channel_totals over the
 * range: per channel (named through Payments' Facts port, a removed channel too) the receipts, the money taken, refunds, net, its share of
 * the money taken (percent, 2 dp, half-up) and the checker's counts (without reference, without photo, verified, disputed, duplicate
 * reference), most money first; and the series of the money per channel, grouped as GET /analytics/sales groups (channels null for a point
 * with no built day).
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->cash = $d->channel('cash');
    $this->telebirr = $d->channel('telebirr');
    $this->awash = $d->channel('awash');
    PaymentChannel::query()->whereKey($this->awash)->firstOrFail()->delete(); // removed since it took the money
    $d->rows->built('2026-11-16')
        ->channel('2026-11-16', $this->cash, ['receipts_count' => 3, 'amount' => '300.00', 'net_amount' => '300.00', 'without_reference_count' => 3, 'without_photo_count' => 3])
        ->channel('2026-11-16', $this->telebirr, ['receipts_count' => 2, 'amount' => '150.00', 'refund_count' => 1, 'refund_amount' => '50.00', 'net_amount' => '100.00',
            'verified_count' => 1, 'disputed_count' => 1, 'duplicate_reference_count' => 1]);
    $d->rows->built('2026-11-17')
        ->channel('2026-11-17', $this->telebirr, ['receipts_count' => 1, 'amount' => '250.00', 'net_amount' => '250.00', 'verified_count' => 1])
        ->channel('2026-11-17', $this->awash, ['receipts_count' => 1, 'amount' => '100.00', 'net_amount' => '100.00']);
    $d->rows->built('2026-11-20');
    $this->week = ['from' => '2026-11-16', 'to' => '2026-11-22'];
});

it('totals each channel over the range with its share of the money taken, most money first, a removed channel still named', function () {
    $response = $this->d->get('channels', $this->week)->assertOk();
    $zero = ['without_reference_count' => 0, 'without_photo_count' => 0, 'verified_count' => 0, 'disputed_count' => 0, 'duplicate_reference_count' => 0];

    expect($response->json('data.channels'))->toBe([
        ['channel' => ['id' => $this->telebirr, 'code' => 'telebirr', 'name_en' => 'Telebirr', 'name_am' => 'ቴሌብር', 'is_removed' => false],
            'receipts_count' => 3, 'amount' => '400.00', 'refund_count' => 1, 'refund_amount' => '50.00', 'net_amount' => '350.00', 'share' => '50.00',
            'without_reference_count' => 0, 'without_photo_count' => 0, 'verified_count' => 2, 'disputed_count' => 1, 'duplicate_reference_count' => 1],
        ['channel' => ['id' => $this->cash, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'is_removed' => false],
            'receipts_count' => 3, 'amount' => '300.00', 'refund_count' => 0, 'refund_amount' => '0.00', 'net_amount' => '300.00', 'share' => '37.50',
            'without_reference_count' => 3, 'without_photo_count' => 3] + $zero,
        ['channel' => ['id' => $this->awash, 'code' => 'awash', 'name_en' => 'Awash Bank', 'name_am' => 'አዋሽ ባንክ', 'is_removed' => true],
            'receipts_count' => 1, 'amount' => '100.00', 'refund_count' => 0, 'refund_amount' => '0.00', 'net_amount' => '100.00', 'share' => '12.50'] + $zero,
    ]);
});

it('gives the money per channel a day by default, and per week or month when asked', function () {
    $days = $this->d->get('channels', ['from' => '2026-11-16', 'to' => '2026-11-18'])->assertOk();

    expect($days->json('data.group'))->toBe('day')
        ->and($days->json('data.series'))->toBe([
            ['key' => '2026-11-16', 'from' => '2026-11-16', 'to' => '2026-11-16', 'days' => 1, 'built_days' => 1, 'channels' => [
                ['channel_id' => $this->cash, 'receipts_count' => 3, 'amount' => '300.00', 'net_amount' => '300.00'],
                ['channel_id' => $this->telebirr, 'receipts_count' => 2, 'amount' => '150.00', 'net_amount' => '100.00'],
            ]],
            ['key' => '2026-11-17', 'from' => '2026-11-17', 'to' => '2026-11-17', 'days' => 1, 'built_days' => 1, 'channels' => [
                ['channel_id' => $this->telebirr, 'receipts_count' => 1, 'amount' => '250.00', 'net_amount' => '250.00'],
                ['channel_id' => $this->awash, 'receipts_count' => 1, 'amount' => '100.00', 'net_amount' => '100.00'],
            ]],
            ['key' => '2026-11-18', 'from' => '2026-11-18', 'to' => '2026-11-18', 'days' => 1, 'built_days' => 0, 'channels' => null],
        ]);

    $week = $this->d->get('channels', $this->week + ['group' => 'week'])->assertOk();
    expect($week->json('data.series'))->toBe([
        ['key' => '2026-11-16', 'from' => '2026-11-16', 'to' => '2026-11-22', 'days' => 7, 'built_days' => 3, 'channels' => [
            ['channel_id' => $this->cash, 'receipts_count' => 3, 'amount' => '300.00', 'net_amount' => '300.00'],
            ['channel_id' => $this->telebirr, 'receipts_count' => 3, 'amount' => '400.00', 'net_amount' => '350.00'],
            ['channel_id' => $this->awash, 'receipts_count' => 1, 'amount' => '100.00', 'net_amount' => '100.00'],
        ]],
    ]);
});

it('adds the channels of the week before with ?compare=previous, and no share when nothing was taken', function () {
    $response = $this->d->get('channels', $this->week + ['compare' => 'previous'])->assertOk();

    expect($response->json('previous.data.channels'))->toBe([])
        ->and($response->json('previous.stale_days'))->toHaveCount(7);

    $this->d->rows->built('2026-11-21')->channel('2026-11-21', $this->telebirr, ['refund_count' => 1, 'refund_amount' => '20.00', 'net_amount' => '-20.00']);
    expect($this->d->get('channels', ['from' => '2026-11-21', 'to' => '2026-11-21'])->json('data.channels.0'))->toMatchArray([
        'amount' => '0.00', 'refund_amount' => '20.00', 'net_amount' => '-20.00', 'share' => null,
    ]);
});

it('answers 304 until a day of the range is rebuilt', function () {
    $tag = (string) $this->d->get('channels', $this->week)->headers->get('ETag');
    expect($this->d->get('channels', $this->week + ['group' => 'week'])->headers->get('ETag'))->not->toBe($tag);
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('channels', $this->week, 'admin', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->rows->rebuilt('2026-11-17', '2026-11-22 07:30:00');
    $this->d->get('channels', $this->week, 'admin', ['If-None-Match' => $tag])->assertOk();
});

it('refuses a bad range, comparison or grouping', function (array $query, string $key, array $extra) {
    $response = $this->d->get('channels', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors() + ['a group that does not exist' => [['group' => 'hour'], 'validation.failed', []]]);

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('channels', [], $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
