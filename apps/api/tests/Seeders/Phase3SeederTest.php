<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Shifts\Contracts\HashChain;
use App\Shared\Models\Branch;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase3Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

pest()->group('feature', 'seeds');

/*
 * Phase3Seeder (step 3.8; phase-3 plan section 8): two days of locked history per station written through the module code, today's
 * open shifts opened from yesterday's closing, the bar's pending closing, a handover note, the templates, the roster and the reasons.
 * On a fresh demo branch DemoSeeder anchors the clock three days back and plays the days forward; on a branch that is already seeded
 * (the live demo, loaded on Phases 0 to 2) it never writes into the past: it only does today's part.
 */

const TRACKED = ['till', 'kitchen', 'bar', 'cake_room', 'bakery'];

function p3Branch(): Branch
{
    return Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
}

function p3Day(int $daysAgo): string
{
    return CarbonImmutable::now(p3Branch()->timezone)->subDays($daysAgo)->toDateString();
}

/** @return array<string, int> */
function p3Rows(int $branchId): array
{
    $rows = [];
    foreach ([
        'shift_templates', 'shift_template_stations', 'shift_instances', 'shift_roster', 'stock_counts', 'stock_count_lines', 'cash_declarations',
        'handover_notes', 'variance_reasons', 'stock_movements', 'stock_balances', 'orders', 'order_lines', 'payments', 'audit_log', 'notifications',
        'transfers', 'production_requests', 'production_batches',
    ] as $table) {
        $rows[$table] = DB::table($table)->where('branch_id', $branchId)->count();
    }

    return $rows;
}

/** The shifts of a station by date, oldest first: [date, template name, status, opening_mode]. */
function p3Shifts(string $station): array
{
    return DB::table('shift_instances')
        ->join('stations', 'stations.id', '=', 'shift_instances.station_id')
        ->leftJoin('shift_templates', 'shift_templates.id', '=', 'shift_instances.template_id')
        ->where('stations.code', $station)
        ->orderBy('shift_instances.id')
        ->get(['shift_instances.date', 'shift_templates.name_en as template', 'shift_instances.status', 'shift_instances.opening_mode'])
        ->map(fn (object $s): array => [(string) $s->date, $s->template, $s->status, $s->opening_mode])
        ->all();
}

function p3Seed(int $phase = 3): void
{
    putenv("SEED_PHASE={$phase}");
    config(['queue.default' => 'database']);
    test()->seed(DemoSeeder::class);
}

afterEach(function () {
    putenv('SEED_PHASE');
});

it('writes two days of locked history per station, today\'s open shifts, the bar\'s pending closing and the Night shift across midnight', function () {
    $before = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];
    p3Seed();

    $d3 = p3Day(3);
    $d2 = p3Day(2);
    $d1 = p3Day(1);
    $d0 = p3Day(0);

    // the clock and the queue are put back
    expect([Carbon::getTestNow(), CarbonImmutable::getTestNow()])->toBe($before)
        ->and(config('queue.default'))->toBe('database');

    // the anchor day's carried shift, the Day shift of each history day, today's open one
    foreach (['till', 'kitchen', 'cake_room'] as $station) {
        expect(p3Shifts($station))->toBe([
            [$d3, null, 'locked', 'carried'],
            [$d2, 'Day', 'locked', 'counted'],
            [$d1, 'Day', 'locked', 'counted'],
            [$d0, null, 'open', 'counted'],
        ], $station);
    }
    // the bar's D-1 closing is left waiting for the manager, so its shift today started without counting
    expect(p3Shifts('bar'))->toBe([
        [$d3, null, 'locked', 'carried'],
        [$d2, 'Day', 'locked', 'counted'],
        [$d1, 'Day', 'pending_approval', 'counted'],
        [$d0, null, 'open', 'carried'],
    ]);
    // the bakery's Night shift: dated D-2, started that night and closed on D-1, and the D-1 Day shift after it
    expect(p3Shifts('bakery'))->toBe([
        [$d3, null, 'locked', 'carried'],
        [$d2, 'Day', 'locked', 'counted'],
        [$d2, 'Night', 'locked', 'counted'],
        [$d1, 'Day', 'locked', 'counted'],
        [$d0, null, 'open', 'counted'],
    ]);

    $branch = p3Branch();
    $zone = $branch->timezone;
    $night = DB::table('shift_instances')->join('shift_templates', 'shift_templates.id', '=', 'shift_instances.template_id')
        ->where('shift_templates.name_en', 'Night')->first(['shift_instances.*']);
    $started = CarbonImmutable::parse($night->started_at, 'UTC')->setTimezone($zone);
    $closing = CarbonImmutable::parse($night->closing_started_at, 'UTC')->setTimezone($zone);
    expect($started->toDateString())->toBe($d2)->and($started->format('H:i'))->toBe('22:05')
        ->and($closing->toDateString())->toBe($d1)->and($closing->format('H:i'))->toBe('05:55');

    // the D-1 Day shift of the bakery opened prefilled from the Night closing
    $dayD1 = DB::table('shift_instances')->join('shift_templates', 'shift_templates.id', '=', 'shift_instances.template_id')
        ->where('shift_templates.name_en', 'Day')->where('shift_instances.date', $d1)->where('shift_instances.station_id', $night->station_id)->first(['shift_instances.id']);
    $nightClosing = DB::table('stock_counts')->where('shift_instance_id', $night->id)->where('type', 'closing')->orderBy('id')->first();
    $opening = DB::table('stock_counts')->where('shift_instance_id', $dayD1->id)->where('type', 'opening')->where('location_id', $nightClosing->location_id)->first();
    $nightLines = DB::table('stock_count_lines')->where('count_id', $nightClosing->id)->pluck('counted_qty', 'item_id')->all();
    $carried = DB::table('stock_count_lines')->where('count_id', $opening->id)->whereNotNull('carried_qty')->pluck('carried_qty', 'item_id')->all();
    expect($carried)->not->toBeEmpty();
    foreach ($carried as $item => $qty) {
        expect($qty)->toBe($nightLines[$item]);
    }
})->group('feature', 'seeds');

it('locks the history: every chain verifies, a closing\'s expected figure is the ledger at its snapshot, cash adds up, and nothing is dated before the anchor', function () {
    p3Seed();
    $branch = p3Branch();
    $chain = app(HashChain::class);

    // one chain per place, every locked count a link, in order
    foreach (DB::table('locations')->where('branch_id', $branch->id)->pluck('id') as $location) {
        $verify = $chain->verify((int) $location);
        $links = DB::table('stock_counts')->where('location_id', $location)->where('status', 'locked')->count();
        expect($verify->ok)->toBeTrue()->and($verify->links)->toBe($links);
    }
    $kitchen = (int) DB::table('locations')->where('branch_id', $branch->id)->where('type', 'kitchen')->value('id');
    $bakery = (int) DB::table('locations')->where('branch_id', $branch->id)->where('type', 'bakery')->value('id');
    expect($chain->verify($kitchen)->links)->toBe(6)->and($chain->verify($bakery)->links)->toBe(8);

    // every submitted closing line: expected_qty is what the ledger held at the snapshot
    $stock = app(Stock::class);
    $checked = 0;
    foreach (DB::table('stock_counts')->where('branch_id', $branch->id)->where('type', 'closing')->whereNotNull('snapshot_at')->get() as $count) {
        foreach (DB::table('stock_count_lines')->where('count_id', $count->id)->get() as $line) {
            $at = CarbonImmutable::parse($count->snapshot_at, 'UTC');
            expect($stock->balanceAt((int) $line->item_id, (int) $count->location_id, $at)->onHand->toString())->toBe($line->expected_qty, "count {$count->id} item {$line->item_id}");
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(50);

    // the planned differences: 500 g of flour in the kitchen on D-2, 3 loaves over at the bakery on D-1, both approved by the manager
    $manager = (int) DB::table('users')->where('phone', '+251900000102')->value('id');
    $adjust = DB::table('stock_movements')->where('branch_id', $branch->id)->where('reason', 'count_adjustment')->where('ref_type', 'stock_count_line')->orderBy('id')->get();
    $byItem = $adjust->groupBy('item_id')->map(fn ($rows) => $rows->pluck('qty')->all())->all();
    $flour = (int) DB::table('items')->where('branch_id', $branch->id)->where('sku', 'ING-FLR-WHT')->value('id');
    expect($byItem[$flour])->toBe(['-500.0000'])
        ->and($adjust->pluck('qty')->contains('3.0000'))->toBeTrue();
    foreach (DB::table('stock_counts')->where('branch_id', $branch->id)->where('status', 'locked')->whereNotNull('decided_by_user_id')->get() as $decided) {
        expect((int) $decided->decided_by_user_id)->toBe($manager);
    }
    $reasons = DB::table('stock_count_lines')->join('variance_reasons', 'variance_reasons.id', '=', 'stock_count_lines.variance_reason_id')
        ->where('stock_count_lines.branch_id', $branch->id)->pluck('variance_reasons.code')->unique()->sort()->values()->all();
    expect($reasons)->toBe(['miscount', 'spoilage']);

    // the bar's closing of D-1 waits for approval: 250 ml of milk short, nothing written for it yet
    $pending = DB::table('stock_counts')->where('branch_id', $branch->id)->where('status', 'pending_approval')->get();
    expect($pending)->toHaveCount(1);
    $milkLine = DB::table('stock_count_lines')->where('count_id', $pending[0]->id)->where('variance_qty', '!=', '0.0000')->get();
    expect($milkLine)->toHaveCount(1)->and($milkLine[0]->variance_qty)->toBe('-250.0000')->and($milkLine[0]->adjustment_movement_id)->toBeNull();

    // cash: each till shift's declared cash sales are the payments taken in it; D-1 closes 20.00 short; every payment is inside a shift
    $totals = app(Totals::class);
    $drawers = DB::table('stock_counts')->join('cash_declarations', 'cash_declarations.count_id', '=', 'stock_counts.id')
        ->where('stock_counts.branch_id', $branch->id)->where('stock_counts.type', 'closing')->orderBy('stock_counts.id')
        ->get(['stock_counts.shift_instance_id', 'cash_declarations.cash_sales_total', 'cash_declarations.variance_total', 'cash_declarations.expected_total', 'cash_declarations.counted_total']);
    expect($drawers)->toHaveCount(3);
    foreach ($drawers as $drawer) {
        expect($drawer->cash_sales_total)->toBe($totals->cash((int) $drawer->shift_instance_id)->amount());
    }
    expect($drawers->pluck('variance_total')->all())->toBe(['0.00', '0.00', '-20.00'])
        ->and(DB::table('payments')->where('branch_id', $branch->id)->whereNull('shift_instance_id')->count())->toBe(0)
        ->and(DB::table('payments')->where('branch_id', $branch->id)->count())->toBe(7);

    // with the rules on, every movement at a place of a station that tracks shifts happened inside a shift
    $unstamped = DB::table('stock_movements')
        ->join('locations', function ($join) {
            $join->on('locations.id', '=', DB::raw('coalesce(stock_movements.to_location_id, stock_movements.from_location_id)'));
        })
        ->join('stations', 'stations.id', '=', 'locations.station_id')
        ->where('stock_movements.branch_id', $branch->id)->where('stations.tracks_shifts', 1)->whereNull('stock_movements.shift_instance_id')->count();
    expect($unstamped)->toBe(0);

    // the oldest row is the anchor (05:30 three local days ago); the newest is not in the future
    $anchor = CarbonImmutable::now($branch->timezone)->subDays(3)->setTime(5, 30)->utc();
    $oldest = CarbonImmutable::parse((string) DB::table('stock_movements')->where('branch_id', $branch->id)->min('created_at'), 'UTC');
    $newest = CarbonImmutable::parse((string) DB::table('stock_movements')->where('branch_id', $branch->id)->max('created_at'), 'UTC');
    expect($oldest->greaterThanOrEqualTo($anchor))->toBeTrue()->and($oldest->diffInHours($anchor, true))->toBeLessThan(1)
        ->and($newest->lessThanOrEqualTo(CarbonImmutable::now()))->toBeTrue();
})->group('feature', 'seeds');

it('writes the templates, the reasons, the roster and a handover note on the kitchen\'s history', function () {
    p3Seed();
    $branch = p3Branch();

    $templates = DB::table('shift_templates')->where('branch_id', $branch->id)->orderBy('id')->get();
    expect($templates->pluck('name_en')->all())->toBe(['Day', 'Night'])
        ->and(substr($templates[0]->start_time, 0, 5))->toBe('06:00')->and(substr($templates[0]->end_time, 0, 5))->toBe('14:00')
        ->and(substr($templates[1]->start_time, 0, 5))->toBe('22:00')->and(substr($templates[1]->end_time, 0, 5))->toBe('06:00');
    $stationsOf = fn (int $template) => DB::table('shift_template_stations')->join('stations', 'stations.id', '=', 'shift_template_stations.station_id')
        ->where('template_id', $template)->whereNull('shift_template_stations.deleted_at')->pluck('stations.code')->sort()->values()->all();
    expect($stationsOf($templates[0]->id))->toBe(['bakery', 'bar', 'cake_room', 'kitchen', 'till'])->and($stationsOf($templates[1]->id))->toBe(['bakery']);
    expect(DB::table('variance_reasons')->where('branch_id', $branch->id)->count())->toBe(5);

    // the bread baker is on the Night shift's roster, scheduled ahead of its start
    $night = DB::table('shift_instances')->where('template_id', $templates[1]->id)->first();
    $baker = (int) DB::table('users')->where('phone', '+251900000109')->value('id');
    expect(DB::table('shift_roster')->where('shift_instance_id', $night->id)->where('user_id', $baker)->exists())->toBeTrue()
        ->and($night->origin)->toBe('scheduled');
    // every shift has someone on its roster
    expect(DB::table('shift_instances')->where('branch_id', $branch->id)->whereNotExists(
        fn ($q) => $q->select(DB::raw(1))->from('shift_roster')->whereColumn('shift_roster.shift_instance_id', 'shift_instances.id'),
    )->count())->toBe(0);

    // one note, left by the chef on the kitchen's D-1 shift, which is what today's shift shows as its handover
    $note = DB::table('handover_notes')->where('branch_id', $branch->id)->sole();
    $shift = DB::table('shift_instances')->where('id', $note->shift_instance_id)->first();
    $kitchen = (int) DB::table('stations')->where('branch_id', $branch->id)->where('code', 'kitchen')->value('id');
    expect((int) $shift->station_id)->toBe($kitchen)->and((string) $shift->date)->toBe(p3Day(1))
        ->and((int) $note->from_user_id)->toBe((int) DB::table('users')->where('phone', '+251900000106')->value('id'));
})->group('feature', 'seeds');

it('prefills today\'s open shifts from yesterday\'s locked closings and declares the till\'s drawer', function () {
    p3Seed();
    $branch = p3Branch();
    $today = DB::table('shift_instances')->where('branch_id', $branch->id)->where('date', p3Day(0))->where('status', 'open')->where('opening_mode', 'counted')->get();
    expect($today)->toHaveCount(4); // the bar started without counting

    foreach ($today as $shift) {
        foreach (DB::table('stock_counts')->where('shift_instance_id', $shift->id)->where('type', 'opening')->get() as $opening) {
            expect($opening->status)->toBe('locked');
            $closing = DB::table('stock_counts')->where('location_id', $opening->location_id)->where('type', 'closing')->where('status', 'locked')->orderByDesc('id')->first();
            foreach (DB::table('stock_count_lines')->where('count_id', $opening->id)->whereNotNull('carried_qty')->get() as $line) {
                $was = DB::table('stock_count_lines')->where('count_id', $closing->id)->where('item_id', $line->item_id)->value('counted_qty');
                expect($line->carried_qty)->toBe($was);
            }
        }
    }
    // the till's drawer: declared at the opening, equal to the float the last closing left
    $till = (int) DB::table('stations')->where('branch_id', $branch->id)->where('code', 'till')->value('id');
    $shift = DB::table('shift_instances')->where('station_id', $till)->where('date', p3Day(0))->first();
    $drawer = DB::table('cash_declarations')->join('stock_counts', 'stock_counts.id', '=', 'cash_declarations.count_id')
        ->where('stock_counts.shift_instance_id', $shift->id)->where('stock_counts.type', 'opening')->first();
    $lastClosing = DB::table('cash_declarations')->join('stock_counts', 'stock_counts.id', '=', 'cash_declarations.count_id')
        ->where('stock_counts.type', 'closing')->where('stock_counts.branch_id', $branch->id)->orderByDesc('stock_counts.id')->first();
    expect($drawer->confirmed_at)->not->toBeNull()->and($drawer->counted_total)->toBe($lastClosing->float_left_total)->and($drawer->variance_total)->toBe('0.00');
})->group('feature', 'seeds');

it('runs twice and the second run writes nothing, not even an audit row or an inbox line', function () {
    p3Seed();
    $first = p3Rows(p3Branch()->id);
    expect($first['shift_instances'])->toBe(21)->and($first['handover_notes'])->toBe(1);

    p3Seed();

    expect(p3Rows(p3Branch()->id))->toBe($first);
})->group('feature', 'seeds');

it('behaves the same with the shift rules off', function () {
    config(['ledger.require_shift_for_station_locations' => false, 'payments.require_shift_for_cash' => false, 'shifts.open_ad_hoc_on_accept' => false]);
    p3Seed();

    expect(DB::table('shift_instances')->where('branch_id', p3Branch()->id)->count())->toBe(21)
        ->and(DB::table('stock_counts')->where('branch_id', p3Branch()->id)->where('status', 'pending_approval')->count())->toBe(1);
})->group('feature', 'seeds');

it('refuses a branch that has no Phase 0 behind it, writing nothing', function () {
    expect(fn () => $this->seed(Phase3Seeder::class))->toThrow(DemoSeedRefused::class);
    expect(DB::table('shift_templates')->count())->toBe(0);
})->group('feature', 'seeds');

it('on a branch already seeded with Phases 0 to 2 writes no history: only today\'s part, nothing dated before the newest movement', function () {
    putenv('SEED_PHASE=2');
    config(['queue.default' => 'database']);
    $this->seed(DemoSeeder::class);
    $branch = p3Branch();
    $newest = CarbonImmutable::parse((string) DB::table('stock_movements')->where('branch_id', $branch->id)->max('created_at'), 'UTC');
    $tables = ['shift_instances', 'shift_roster', 'stock_counts', 'stock_count_lines', 'cash_declarations', 'handover_notes', 'shift_templates', 'shift_template_stations', 'stock_movements', 'audit_log', 'payments', 'orders'];
    $maxIds = [];
    foreach ($tables as $table) {
        $maxIds[$table] = (int) DB::table($table)->max('id');
    }
    $movements = DB::table('stock_movements')->where('branch_id', $branch->id)->count();
    $sampleOrders = DB::table('orders')->where('branch_id', $branch->id)->count();

    // a day later, the way `bakery:seed-demo --force` runs on the live demo branch
    $this->travelTo($newest->addDay()->setTime(9, 0));
    $exit = Artisan::call('bakery:seed-demo', ['--phase' => '3']);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toContain('history not written');
    // the history of a fresh branch is absent: no locked shift, no closing, no adjustment
    expect(DB::table('shift_instances')->where('branch_id', $branch->id)->where('status', 'locked')->count())->toBe(0)
        ->and(DB::table('stock_counts')->where('branch_id', $branch->id)->where('type', 'closing')->count())->toBe(0)
        ->and(DB::table('stock_movements')->where('branch_id', $branch->id)->where('ref_type', 'stock_count_line')->count())->toBe(0);
    // the templates, the reasons and the roster are there
    expect(DB::table('shift_templates')->where('branch_id', $branch->id)->pluck('name_en')->all())->toBe(['Day', 'Night'])
        ->and(DB::table('variance_reasons')->where('branch_id', $branch->id)->count())->toBe(5)
        ->and(DB::table('shift_roster')->count())->toBeGreaterThan(5);
    // Phase 1's sample orders of the new day are the only movements Phases 0 to 2 add on a later day; Phase3Seeder adds none of its own
    expect(DB::table('stock_movements')->where('branch_id', $branch->id)->where('ref_type', 'stock_count_line')->count())->toBe(0)
        ->and(DB::table('orders')->where('branch_id', $branch->id)->count())->toBeGreaterThanOrEqual($sampleOrders);

    // nothing the run wrote is older than the newest movement that was already there
    foreach ($tables as $table) {
        $stamp = DB::table($table)->where('id', '>', $maxIds[$table])->min('created_at');
        if ($stamp !== null) {
            expect(CarbonImmutable::parse((string) $stamp, 'UTC')->greaterThanOrEqualTo($newest))->toBeTrue("{$table} has a row dated {$stamp}, before the newest movement {$newest}");
        }
    }
    expect(DB::table('stock_movements')->where('branch_id', $branch->id)->where('id', '<=', $maxIds['stock_movements'])->count())->toBe($movements);

    // a second run of it writes nothing
    $rows = p3Rows($branch->id);
    expect(Artisan::call('bakery:seed-demo', ['--phase' => '3']))->toBe(0)
        ->and(p3Rows($branch->id))->toBe($rows);
})->group('feature', 'seeds');
