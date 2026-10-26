<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use Tests\Support\Dashboards;
use Tests\Support\SummaryRows;

pest()->group('module:analytics', 'feature');

/*
 * GET /api/v1/analytics/overview (phase-4 plan section 4.6, step 4.4c; analytics.view: admin, manager). The range's headline figures,
 * summed over its built days from daily_sales, daily_channel_totals and daily_item_metrics: revenue, net revenue (cash basis: refunds by
 * the day they were paid back), orders, the average ticket (revenue / orders, half-up, once), items, refunds, voids, cancellations,
 * revenue by order type, the channels with their share of the money taken, and the five products with the most revenue. Every answer
 * of the dashboards is {from, to, timezone, as_of, stale_days, data, previous?}: as_of the oldest rebuilt_at of the range's built days,
 * stale_days the days with no analytics_days row (not built, never counted as zero; R-U), previous the same answer for the same number
 * of days just before, with ?compare=previous. Conditional: the ETag is the days' versions, so a poll is 304 until a rebuild moves one.
 * Today is Sunday 2026-11-22 (Dashboards).
 */

beforeEach(function () {
    $this->d = Dashboards::open($this);
    $d = $this->d;
    $this->cash = $d->channel('cash');
    $this->telebirr = $d->channel('telebirr');
    $this->p = [
        'croissant' => $d->product('Croissant', 'ክሮሳንት'),
        'cake' => $d->product('Cake', 'ኬክ'),
        'latte' => $d->product('Latte', 'ላቴ'),
        'water' => $d->product('Water', 'ውሃ'),
        'tea' => $d->product('Tea', 'ሻይ'),
        'bread' => $d->product('Bread', 'ዳቦ'),
    ];
    $flour = $d->ingredient('Flour', 'ዱቄት');

    $d->rows->built('2026-11-16', 1, '2026-11-17 21:05:00')
        ->sales('2026-11-16', ['orders_count' => 2, 'items_qty' => '5.0000', 'revenue' => '300.00', 'net_revenue' => '300.00', 'avg_ticket' => '150.00',
            'dine_in_revenue' => '100.00', 'takeaway_revenue' => '200.00', 'voided_count' => 1, 'voided_total' => '50.00', 'cancelled_count' => 1])
        ->channel('2026-11-16', $this->cash, ['receipts_count' => 1, 'amount' => '200.00', 'net_amount' => '200.00'])
        ->channel('2026-11-16', $this->telebirr, ['receipts_count' => 1, 'amount' => '100.00', 'net_amount' => '100.00'])
        ->item('2026-11-16', $this->p['croissant']->id, ['units_sold_qty' => '2.0000', 'revenue' => '120.00'])
        ->item('2026-11-16', $this->p['water']->id, ['units_sold_qty' => '4.0000', 'revenue' => '100.00'])
        ->item('2026-11-16', $this->p['bread']->id, ['units_sold_qty' => '2.0000', 'revenue' => '50.00'])
        ->item('2026-11-16', $flour->id, ['kind' => 'ingredient', 'waste_qty' => '1000.0000']);
    $d->rows->built('2026-11-17')
        ->sales('2026-11-17', ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '120.00', 'refund_count' => 1, 'refund_total' => '20.00',
            'net_revenue' => '100.00', 'avg_ticket' => '120.00', 'delivery_revenue' => '120.00'])
        ->channel('2026-11-17', $this->cash, ['receipts_count' => 1, 'amount' => '120.00', 'refund_count' => 1, 'refund_amount' => '20.00', 'net_amount' => '100.00'])
        ->item('2026-11-17', $this->p['cake']->id, ['units_sold_qty' => '1.0000', 'revenue' => '200.00']);
    $d->rows->built('2026-11-20'); // a quiet day: built, nothing sold
    $d->rows->built('2026-11-22', 2, '2026-11-22 06:30:00')
        ->sales('2026-11-22', ['orders_count' => 3, 'items_qty' => '4.5000', 'revenue' => '455.50', 'net_revenue' => '455.50', 'avg_ticket' => '151.83',
            'takeaway_revenue' => '455.50'])
        ->channel('2026-11-22', $this->cash, ['receipts_count' => 2, 'amount' => '300.00', 'net_amount' => '300.00'])
        ->channel('2026-11-22', $this->telebirr, ['receipts_count' => 1, 'amount' => '155.50', 'net_amount' => '155.50'])
        ->item('2026-11-22', $this->p['croissant']->id, ['units_sold_qty' => '3.0000', 'revenue' => '180.00'])
        ->item('2026-11-22', $this->p['latte']->id, ['units_sold_qty' => '1.5000', 'revenue' => '150.00'])
        ->item('2026-11-22', $this->p['tea']->id, ['units_sold_qty' => '5.0000', 'revenue' => '75.50']);
    // the week before, for ?compare=previous
    $d->rows->built('2026-11-09', 1, '2026-11-10 21:05:00');
    $d->rows->built('2026-11-15')
        ->sales('2026-11-15', ['orders_count' => 1, 'items_qty' => '1.0000', 'revenue' => '80.00', 'net_revenue' => '80.00', 'avg_ticket' => '80.00', 'takeaway_revenue' => '80.00'])
        ->channel('2026-11-15', $this->cash, ['receipts_count' => 1, 'amount' => '80.00', 'net_amount' => '80.00'])
        ->item('2026-11-15', $this->p['cake']->id, ['units_sold_qty' => '1.0000', 'revenue' => '80.00']);
    // another branch's figures on the same days are never read
    $other = Branch::factory()->create();
    (new SummaryRows($other->id))->built('2026-11-16')->sales('2026-11-16', ['orders_count' => 9, 'revenue' => '999.00', 'net_revenue' => '999.00']);
    actInBranch($d->branch);

    $this->label = fn (Item $item): array => [
        'id' => $item->id, 'name_en' => $item->name_en, 'name_am' => $item->name_am, 'kind' => 'product', 'unit' => 'pcs', 'category' => null,
        'is_new' => false, 'is_active' => true,
    ];
    $this->week = ['from' => '2026-11-16', 'to' => '2026-11-22'];
});

it('sums the range\'s built days: revenue, refunds, orders, the average ticket, voids, the order types, the channels\' shares and the top five products', function () {
    $response = $this->d->get('overview', $this->week)->assertOk()->assertHeader('Cache-Control', ConditionalPoll::CACHE_CONTROL)->assertHeader('ETag');
    $p = $this->p;

    expect($response->json())->toBe([
        'from' => '2026-11-16',
        'to' => '2026-11-22',
        'timezone' => 'Africa/Addis_Ababa',
        'as_of' => '2026-11-17T21:05:00Z',
        'stale_days' => ['2026-11-18', '2026-11-19', '2026-11-21'],
        'data' => [
            'revenue' => '875.50',
            'net_revenue' => '855.50',
            'orders_count' => 6,
            'avg_ticket' => '145.92',
            'items_qty' => '10.5000',
            'refund_count' => 1,
            'refund_total' => '20.00',
            'voided_count' => 1,
            'voided_total' => '50.00',
            'cancelled_count' => 1,
            'by_type' => ['dine_in' => '100.00', 'takeaway' => '655.50', 'delivery' => '120.00', 'advance' => '0.00'],
            'channels' => [
                ['channel' => ['id' => $this->cash, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'is_removed' => false],
                    'receipts_count' => 4, 'amount' => '620.00', 'net_amount' => '600.00', 'share' => '70.82'],
                ['channel' => ['id' => $this->telebirr, 'code' => 'telebirr', 'name_en' => 'Telebirr', 'name_am' => 'ቴሌብር', 'is_removed' => false],
                    'receipts_count' => 2, 'amount' => '255.50', 'net_amount' => '255.50', 'share' => '29.18'],
            ],
            'top_items' => [
                ['item' => ($this->label)($p['croissant']), 'units_sold_qty' => '5.0000', 'revenue' => '300.00'],
                ['item' => ($this->label)($p['cake']), 'units_sold_qty' => '1.0000', 'revenue' => '200.00'],
                ['item' => ($this->label)($p['latte']), 'units_sold_qty' => '1.5000', 'revenue' => '150.00'],
                ['item' => ($this->label)($p['water']), 'units_sold_qty' => '4.0000', 'revenue' => '100.00'],
                ['item' => ($this->label)($p['tea']), 'units_sold_qty' => '5.0000', 'revenue' => '75.50'],
            ],
        ],
    ]);
});

it('adds the same answer for the seven days before with ?compare=previous', function () {
    $response = $this->d->get('overview', $this->week + ['compare' => 'previous'])->assertOk();

    expect($response->json('data.revenue'))->toBe('875.50')
        ->and($response->json('previous'))->toBe([
            'from' => '2026-11-09',
            'to' => '2026-11-15',
            'as_of' => '2026-11-10T21:05:00Z',
            'stale_days' => ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'],
            'data' => [
                'revenue' => '80.00', 'net_revenue' => '80.00', 'orders_count' => 1, 'avg_ticket' => '80.00', 'items_qty' => '1.0000', 'refund_count' => 0,
                'refund_total' => '0.00', 'voided_count' => 0, 'voided_total' => '0.00', 'cancelled_count' => 0,
                'by_type' => ['dine_in' => '0.00', 'takeaway' => '80.00', 'delivery' => '0.00', 'advance' => '0.00'],
                'channels' => [
                    ['channel' => ['id' => $this->cash, 'code' => 'cash', 'name_en' => 'Cash', 'name_am' => 'ጥሬ ገንዘብ', 'is_removed' => false],
                        'receipts_count' => 1, 'amount' => '80.00', 'net_amount' => '80.00', 'share' => '100.00'],
                ],
                'top_items' => [['item' => ($this->label)($this->p['cake']), 'units_sold_qty' => '1.0000', 'revenue' => '80.00']],
            ],
        ]);
    expect($this->d->get('overview', $this->week)->json())->not->toHaveKey('previous');
});

it('reads the last thirty days by default; a range with no built day has zero figures, no share, and every day stale', function () {
    $this->d->get('overview')->assertOk()
        ->assertJsonPath('from', '2026-10-24')
        ->assertJsonPath('to', '2026-11-22')
        ->assertJsonPath('data.revenue', '955.50')
        ->assertJsonCount(30 - 6, 'stale_days');

    $this->d->get('overview', ['from' => '2026-11-18', 'to' => '2026-11-19'])->assertOk()
        ->assertJsonPath('as_of', null)
        ->assertJsonPath('stale_days', ['2026-11-18', '2026-11-19'])
        ->assertJsonPath('data.revenue', '0.00')
        ->assertJsonPath('data.avg_ticket', '0.00')
        ->assertJsonPath('data.channels', [])
        ->assertJsonPath('data.top_items', []);
});

it('answers 304 for an unchanged range after the settling window, and a new tag once a day of the range (or of the period compared) is rebuilt', function () {
    $first = $this->d->get('overview', $this->week)->assertOk();
    $tag = (string) $first->headers->get('ETag');
    $compared = (string) $this->d->get('overview', $this->week + ['compare' => 'previous'])->headers->get('ETag');
    expect($compared)->not->toBe($tag);
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds();

    $this->d->get('overview', $this->week, 'admin', ['If-None-Match' => $tag])->assertStatus(304)->assertHeader('ETag', $tag)->assertContent('');
    $this->d->get('overview', $this->week, 'manager', ['If-None-Match' => 'W/'.$tag])->assertStatus(304);
    $this->d->get('overview', ['from' => '2026-11-17', 'to' => '2026-11-22'], 'admin', ['If-None-Match' => $tag])->assertOk();

    $this->d->rows->rebuilt('2026-11-15', '2026-11-22 07:10:00'); // the period compared, not the range
    $this->d->get('overview', $this->week, 'admin', ['If-None-Match' => $tag])->assertStatus(304);
    $this->d->get('overview', $this->week + ['compare' => 'previous'], 'admin', ['If-None-Match' => $compared])->assertOk();

    $this->d->rows->rebuilt('2026-11-22', '2026-11-22 07:10:00')->sales('2026-11-22', ['revenue' => '500.00']);
    $moved = $this->d->get('overview', $this->week, 'admin', ['If-None-Match' => $tag])->assertOk()->assertJsonPath('data.revenue', '920.00');
    expect($moved->headers->get('ETag'))->not->toBe($tag);
});

it('refuses a bad range or comparison', function (array $query, string $key, array $extra) {
    $response = $this->d->get('overview', $query)->assertStatus(422)->assertJsonPath('message_key', $key);
    foreach ($extra as $path => $value) {
        $response->assertJsonPath($path, $value);
    }
})->with(Dashboards::rangeErrors());

it('is for the admin and the manager only', function (string $role, bool $allowed) {
    $response = $this->d->get('overview', $this->week, $role);

    $allowed ? $response->assertOk() : $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
})->with(Dashboards::roles(['admin', 'manager']));
