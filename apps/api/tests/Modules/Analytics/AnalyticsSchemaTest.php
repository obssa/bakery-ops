<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The summary tables of Analytics (phase-4 plan section 3, M12 to M16; ADR 0014). Derived data: every row is rebuilt from the Facts
 * ports, so the tables have no client_uuid, no actor stamp and no deleted_at (a rebuild deletes the day's rows physically and writes
 * them again). branch_id leads every primary key (rule 8); money is DECIMAL(12,2), quantities DECIMAL(14,4) (NumericColumnsTest);
 * the enum lists are CHECKs on both engines.
 */

$columns = [
    'analytics_days' => ['branch_id', 'day', 'version', 'rebuilt_at', 'trigger', 'duration_ms', 'row_counts', 'fingerprint', 'changed'],
    'daily_sales' => ['branch_id', 'day', 'orders_count', 'items_qty', 'subtotal', 'discount_total', 'tax_total', 'revenue', 'avg_ticket', 'voided_count',
        'voided_total', 'cancelled_count', 'refund_count', 'refund_total', 'net_revenue', 'dine_in_revenue', 'takeaway_revenue', 'delivery_revenue',
        'advance_revenue', 'rebuilt_at'],
    'daily_hour_sales' => ['branch_id', 'day', 'hour', 'orders_count', 'items_qty', 'revenue', 'rebuilt_at'],
    'daily_channel_totals' => ['branch_id', 'day', 'channel_id', 'receipts_count', 'amount', 'refund_count', 'refund_amount', 'net_amount',
        'without_reference_count', 'without_photo_count', 'verified_count', 'disputed_count', 'duplicate_reference_count', 'rebuilt_at'],
    'daily_item_metrics' => ['branch_id', 'day', 'item_id', 'category_id', 'kind', 'units_sold_qty', 'refunded_qty', 'produced_qty', 'consumed_qty',
        'waste_qty', 'purchased_qty', 'adjusted_qty', 'stockout_count', 'closing_on_hand_qty', 'revenue', 'cogs_total', 'margin_total',
        'closing_value_total', 'cogs_complete', 'rebuilt_at'],
    // prep_samples and prep_seconds_sum: step 4.4c (a range's exact mean prep time)
    'daily_station_metrics' => ['branch_id', 'day', 'station_id', 'tickets_count', 'lines_served', 'lines_rejected', 'avg_prep_seconds',
        'p90_prep_seconds', 'prep_samples', 'prep_seconds_sum', 'batches_finished', 'produced_qty', 'waste_qty', 'shifts_count', 'shifts_locked_count', 'variance_line_count',
        'cash_variance_total', 'stockout_count', 'rebuilt_at'],
    'daily_user_metrics' => ['branch_id', 'day', 'user_id', 'role_kind', 'orders_count', 'items_qty', 'revenue', 'payments_count', 'payments_amount',
        'voids_count', 'refunds_count', 'lines_prepared', 'avg_prep_seconds', 'prep_samples', 'prep_seconds_sum', 'rebuilt_at'],
];

$primaryKeys = [
    'analytics_days' => ['branch_id', 'day'],
    'daily_sales' => ['branch_id', 'day'],
    'daily_hour_sales' => ['branch_id', 'day', 'hour'],
    'daily_channel_totals' => ['branch_id', 'day', 'channel_id'],
    'daily_item_metrics' => ['branch_id', 'day', 'item_id'],
    'daily_station_metrics' => ['branch_id', 'day', 'station_id'],
    'daily_user_metrics' => ['branch_id', 'day', 'user_id', 'role_kind'],
];

it('creates the seven tables with the columns of the plan, in that order, and nothing operational', function () use ($columns) {
    foreach ($columns as $table => $expected) {
        expect(Schema::getColumnListing($table))->toBe($expected, $table);
        expect(array_intersect(['client_uuid', 'deleted_at', 'created_by_user_id', 'acting_user_id'], Schema::getColumnListing($table)))->toBe([], $table);
    }
})->group('module:analytics', 'feature');

it('keys every table by branch first, then the day and the dimension', function () use ($primaryKeys) {
    foreach ($primaryKeys as $table => $key) {
        $primary = array_values(array_filter(Schema::getIndexes($table), fn (array $index): bool => $index['primary']));
        expect($primary)->toHaveCount(1, $table)->and($primary[0]['columns'])->toBe($key, $table);
    }
})->group('module:analytics', 'feature');

it('refuses a trigger, an hour, a kind or a role kind outside its list', function () {
    $branch = Branch::factory()->create();
    $day = fn (array $over = []): array => $over + [
        'branch_id' => $branch->id, 'day' => '2026-11-20', 'version' => 1, 'rebuilt_at' => '2026-11-20 21:00:00', 'trigger' => 'manual',
        'duration_ms' => 1, 'row_counts' => '{}', 'fingerprint' => str_repeat('a', 64), 'changed' => true,
    ];
    foreach (['shift_locked', 'nightly', 'manual', 'touch', 'seed'] as $i => $trigger) {
        DB::table('analytics_days')->insert($day(['trigger' => $trigger, 'day' => '2026-11-0'.($i + 1)]));
    }
    expect(fn () => DB::table('analytics_days')->insert($day(['trigger' => 'cron'])))->toThrow(QueryException::class);

    $hour = ['branch_id' => $branch->id, 'day' => '2026-11-20', 'orders_count' => 1, 'items_qty' => '1', 'revenue' => '1.00', 'rebuilt_at' => '2026-11-20 21:00:00'];
    DB::table('daily_hour_sales')->insert($hour + ['hour' => 23]);
    expect(fn () => DB::table('daily_hour_sales')->insert($hour + ['hour' => 24]))->toThrow(QueryException::class);

    $item = Item::factory()->ingredient()->create(['branch_id' => $branch->id]);
    expect(fn () => DB::table('daily_item_metrics')->insert(['branch_id' => $branch->id, 'day' => '2026-11-20', 'item_id' => $item->id, 'kind' => 'service', 'rebuilt_at' => '2026-11-20 21:00:00']))
        ->toThrow(QueryException::class);

    seedRoles();
    $user = makeUser($branch, 'cashier');
    $person = ['branch_id' => $branch->id, 'day' => '2026-11-20', 'user_id' => $user->id, 'rebuilt_at' => '2026-11-20 21:00:00'];
    foreach (['waiter', 'cashier', 'station'] as $kind) {
        DB::table('daily_user_metrics')->insert($person + ['role_kind' => $kind]);
    }
    expect(fn () => DB::table('daily_user_metrics')->insert($person + ['role_kind' => 'manager']))->toThrow(QueryException::class);
})->group('module:analytics', 'feature');

it('refuses a second row for the same branch, day and dimension (the primary key)', function () {
    $branch = Branch::factory()->create();
    $row = ['branch_id' => $branch->id, 'day' => '2026-11-20', 'rebuilt_at' => '2026-11-20 21:00:00'];
    DB::table('daily_sales')->insert($row);

    expect(fn () => DB::table('daily_sales')->insert($row))->toThrow(QueryException::class);
})->group('module:analytics', 'feature');
