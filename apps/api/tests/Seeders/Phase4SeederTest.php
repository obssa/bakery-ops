<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Shifts\Contracts\HashChain;
use App\Shared\Models\Branch;
use App\Shared\Time\LocalDay;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DayPlan;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoSeedRefused;
use Database\Seeders\Demo\PlannedDay;
use Database\Seeders\Demo\PlannedOrder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase4Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

pest()->group('feature', 'seeds:p4');

/*
 * Phase4Seeder (step 4.6a; phase-4 plan section 8): on a fresh demo branch, with SEED_PHASE 4, the clock is anchored
 * DayPlan::historyDays() + 2 days back and every day after the anchor is played to yesterday through the module code, with the Phase 4
 * day plan (DayPlan::phaseFour, mt_srand(4040)); every day written is rebuilt (trigger seed); today is left for the live demo. A
 * branch that already holds data never gets history. The window is small here (7 days, 8 played) so the test runs in minutes; CI's
 * e2e-p4 seeds the full 90 days. Every figure of section 8's list is checked against the plan or against the raw rows.
 */

const P4_DAYS = 7;

const P4_STATIONS = ['till', 'kitchen', 'bar', 'cake_room', 'bakery'];

/** The tables a run writes into, by branch, for "a second run writes nothing". */
const P4_TABLES = [
    'orders', 'order_lines', 'order_events', 'payments', 'payment_refunds', 'stored_files', 'stock_movements', 'stock_balances', 'stock_counts',
    'stock_count_lines', 'cash_declarations', 'shift_instances', 'shift_roster', 'handover_notes', 'transfers', 'purchases', 'purchase_lines',
    'production_requests', 'production_batches', 'notifications', 'stock_alert_states', 'audit_log', 'analytics_days', 'daily_sales',
    'daily_channel_totals', 'receipt_settings', 'suppliers', 'item_cost_history',
];

function p4Branch(): Branch
{
    return Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
}

/** @return list<string> the waiters' phones, in the order the plan draws them */
function p4Waiters(): array
{
    return array_values(array_map(fn (array $p): string => $p['phone'], array_filter(DemoBakery::people(), fn (array $p): bool => $p['role'] === 'waiter')));
}

/** @return list<PlannedDay> the plan the seed played: the opening day to yesterday */
function p4Plan(): array
{
    $today = CarbonImmutable::now(DemoClock::TIMEZONE);

    return DayPlan::phaseFour($today->subDays(P4_DAYS + 1)->toDateString(), $today->subDay()->toDateString(), p4Waiters());
}

function p4Seed(): void
{
    config(['bakery.demo.history_days' => P4_DAYS, 'queue.default' => 'database']);
    putenv('SEED_PHASE=4');
    test()->seed(DemoSeeder::class);
}

/** @return array{0: string, 1: string} the UTC bounds [start, end) of a local day */
function p4Bounds(string $day): array
{
    [$start, $end] = LocalDay::of($day, DemoClock::TIMEZONE)->utcBounds();

    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/** @return array<string, int> */
function p4Rows(int $branchId): array
{
    $rows = [];
    foreach (P4_TABLES as $table) {
        $rows[$table] = DB::table($table)->where('branch_id', $branchId)->count();
    }
    $rows['analytics_versions'] = (int) DB::table('analytics_days')->where('branch_id', $branchId)->sum('version');

    return $rows;
}

function p4Sum(iterable $amounts): string
{
    $sum = BigDecimal::zero();
    foreach ($amounts as $amount) {
        $sum = $sum->plus(BigDecimal::of((string) $amount));
    }

    return (string) $sum->toScale(2);
}

beforeEach(function () {
    Storage::fake((string) config('files.disk'));
});

afterEach(function () {
    putenv('SEED_PHASE');
});

it('plays the planned days through the module code: shifts, orders, payments with their photos, voids, checks, stock-outs and differences', function () {
    $before = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];
    p4Seed();
    expect([Carbon::getTestNow(), CarbonImmutable::getTestNow()])->toBe($before)
        ->and(config('queue.default'))->toBe('database');

    $branch = p4Branch();
    $b = $branch->id;
    $zone = $branch->timezone;
    $plan = p4Plan();
    expect($plan)->toHaveCount(P4_DAYS + 1)->and(DayPlan::REFUND_PCT)->toBe(0);
    $anchor = CarbonImmutable::now($zone)->subDays(P4_DAYS + 2)->toDateString();
    $today = CarbonImmutable::now($zone)->toDateString();

    // the oldest row is the anchor, 05:30 local, D-(N+2)
    $oldest = CarbonImmutable::parse((string) DB::table('stock_movements')->where('branch_id', $b)->min('created_at'), 'UTC')->setTimezone($zone);
    expect($oldest->toDateString())->toBe($anchor)->and($oldest->format('H:i'))->toBe('05:30');

    // shifts: one Day shift per station and day, locked; the bakery's Night shift on the planned Fridays, from 22:05 to 05:55; today open
    $shifts = DB::table('shift_instances')->leftJoin('shift_templates', 'shift_templates.id', '=', 'shift_instances.template_id')
        ->join('stations', 'stations.id', '=', 'shift_instances.station_id')->where('shift_instances.branch_id', $b)
        ->get(['shift_instances.*', 'shift_templates.name_en as template', 'stations.code as station']);
    foreach ($plan as $day) {
        foreach (P4_STATIONS as $station) {
            $dayShifts = $shifts->where('date', $day->date)->where('station', $station)->where('template', 'Day');
            expect($dayShifts)->toHaveCount(1, "{$day->date} {$station}")->and($dayShifts->first()->status)->toBe('locked');
        }
        $night = $shifts->where('date', $day->date)->where('template', 'Night');
        expect($night)->toHaveCount($day->night ? 1 : 0, "night {$day->date}");
        if ($day->night) {
            $n = $night->first();
            expect($n->status)->toBe('locked')
                ->and(CarbonImmutable::parse($n->started_at, 'UTC')->setTimezone($zone)->format('Y-m-d H:i'))->toBe("{$day->date} 22:05")
                ->and(CarbonImmutable::parse($n->closing_started_at, 'UTC')->setTimezone($zone)->format('H:i'))->toBe('05:55')
                ->and(CarbonImmutable::parse($n->closing_started_at, 'UTC')->setTimezone($zone)->toDateString())->toBe(CarbonImmutable::parse($day->date)->addDay()->toDateString());
        }
    }
    expect(collect($plan)->contains(fn (PlannedDay $d): bool => $d->night))->toBeTrue();
    expect($shifts->where('date', $today)->where('status', 'open')->pluck('station')->sort()->values()->all())->toBe(['bakery', 'bar', 'cake_room', 'kitchen', 'till'])
        ->and($shifts->whereNotIn('status', ['locked', 'open'])->count())->toBe(0);

    // orders and payments: the plan's volumes and outcomes, day by day
    $channels = DB::table('payment_channels')->where('branch_id', $b)->pluck('code', 'id')->all();
    $allowed = array_merge(array_keys(DayPlan::CHANNEL_MIX), array_keys(DayPlan::BANK_CHANNELS));
    foreach ($plan as $day) {
        $orders = collect($day->orders);
        $rows = DB::table('orders')->where('branch_id', $b)->where('business_date', $day->date)->get();
        expect($rows)->toHaveCount(DayPlan::ordersOn($day->date), "orders {$day->date}")
            ->and($rows)->toHaveCount($orders->count())
            ->and($rows->where('status', 'voided')->count())->toBe($orders->where('voidOrder', true)->count(), "voided {$day->date}")
            ->and($rows->where('status', 'closed')->count())->toBe($orders->where('voidOrder', false)->count(), "closed {$day->date}");

        [$from, $to] = p4Bounds($day->date);
        $payments = DB::table('payments')->where('branch_id', $b)->where('received_at', '>=', $from)->where('received_at', '<', $to)->get();
        $paid = $orders->where('voidOrder', false);
        expect($payments->whereNotNull('batch_uuid')->pluck('batch_uuid')->unique()->count())->toBe($paid->where('split', true)->count(), "splits {$day->date}")
            ->and($payments->where('status', 'voided')->count())->toBe($paid->where('voidPayment', true)->count(), "payment voids {$day->date}")
            ->and($payments->where('status', 'disputed')->count())->toBe($paid->where('check', PlannedOrder::CHECK_DISPUTE)->count(), "disputed {$day->date}")
            ->and($payments->where('status', 'verified')->count())->toBe($paid->whereIn('check', [PlannedOrder::CHECK_VERIFY, PlannedOrder::CHECK_DISPUTE_RESOLVED])->count(), "verified {$day->date}")
            ->and($payments->whereNull('shift_instance_id')->count())->toBe(0);
        foreach ($payments as $payment) {
            expect($channels[$payment->channel_id])->toBeIn($allowed);
        }
    }
    $all = collect($plan)->flatMap(fn (PlannedDay $d): array => $d->orders);
    foreach (['voidOrder', 'split', 'voidPayment'] as $kind) {
        expect($all->where($kind, true)->count())->toBeGreaterThan(0, $kind);
    }
    expect($all->where('check', PlannedOrder::CHECK_DISPUTE_RESOLVED)->count())->toBeGreaterThan(0);

    // every digital payment: a receipt photo stored on the disk through Files::put just before it, attached to it, and a SEED reference
    $digital = DB::table('payments')->join('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
        ->where('payments.branch_id', $b)->where('payment_channels.code', '<>', 'cash')->get(['payments.*']);
    expect($digital->count())->toBeGreaterThan(20);
    $hashes = [];
    foreach ($digital as $payment) {
        $file = DB::table('stored_files')->where('id', $payment->receipt_file_id)->first();
        $received = CarbonImmutable::parse($payment->received_at, 'UTC');
        expect($file)->not->toBeNull("payment {$payment->id}")
            ->and($file->purpose)->toBe('receipt')
            ->and($file->attached_type)->toBe('payment')->and((int) $file->attached_id)->toBe($payment->id)
            ->and(Storage::disk($file->disk)->exists($file->key))->toBeTrue()
            ->and(CarbonImmutable::parse($file->created_at, 'UTC')->lessThanOrEqualTo($received))->toBeTrue()
            ->and(CarbonImmutable::parse($file->created_at, 'UTC')->diffInHours($received, true))->toBeLessThan(12)
            ->and($payment->reference_no)->toMatch('/^SEED-'.$received->setTimezone($zone)->format('ymd').'-\d+$/');
        $hashes[] = $file->sha256;
        // no EXIF (APP1), no other APPn block, no comment: Files::put re-encoded it
        $segments = imgSegments((string) Storage::disk($file->disk)->get($file->key));
        expect(array_values(array_filter($segments, fn (int $m): bool => ($m >= 0xE1 && $m <= 0xEF) || $m === 0xFE)))->toBe([]);
    }
    expect(array_unique($hashes))->toHaveCount(count($hashes))
        ->and(DB::table('stored_files')->where('branch_id', $b)->whereNull('attached_id')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('branch_id', $b)->where('action', 'file.upload')->count())->toBe(DB::table('stored_files')->where('branch_id', $b)->count());

    // the people's actions are audited as on the screens
    foreach (['payment.verify' => 'verified_at', 'payment.dispute' => 'disputed_at', 'payment.void' => 'voided_at'] as $action => $column) {
        expect(DB::table('audit_log')->where('branch_id', $b)->where('action', $action)->count())->toBe(DB::table('payments')->where('branch_id', $b)->whereNotNull($column)->count(), $action);
    }
    expect(DB::table('audit_log')->where('branch_id', $b)->where('action', 'order.void')->count())->toBe(DB::table('orders')->where('branch_id', $b)->where('status', 'voided')->count());

    // every place's chain verifies; every counted line's expected figure is the ledger at the count's snapshot
    $chain = app(HashChain::class);
    foreach (DB::table('locations')->where('branch_id', $b)->pluck('id') as $location) {
        $verify = $chain->verify((int) $location);
        expect($verify->ok())->toBeTrue("chain {$location}")->and($verify->checked)->toBe(DB::table('stock_counts')->where('location_id', $location)->where('status', 'locked')->count());
    }
    $stock = app(Stock::class);
    foreach (DB::table('stock_counts')->where('branch_id', $b)->whereNotNull('snapshot_at')->get() as $count) {
        $at = CarbonImmutable::parse($count->snapshot_at, 'UTC');
        foreach (DB::table('stock_count_lines')->where('count_id', $count->id)->get() as $line) {
            expect($stock->balanceAt((int) $line->item_id, (int) $count->location_id, $at)->onHand->toString())->toBe($line->expected_qty, "count {$count->id} item {$line->item_id}");
        }
    }

    // the ledger oracle: the raw movements, the balances and the view agree, and nothing is below zero
    $oracle = ledgerOracle($b);
    expect($oracle['balances'])->toBe($oracle['movements'])->and($oracle['view'])->toBe($oracle['movements']);
    foreach ($oracle['movements'] as $key => [$onHand, $reserved]) {
        expect(BigDecimal::of($onHand)->isNegative() || BigDecimal::of($reserved)->isNegative())->toBeFalse($key);
    }

    // the closings equal the ledger except on the planned days: the variance lines and the drawer's differences are exactly the plan's
    $planned = [];
    $plannedCash = [];
    foreach ($plan as $day) {
        foreach ($day->differences as $station => $items) {
            foreach ($items as $sku => [$qty]) {
                $planned[] = "{$day->date} {$station} {$sku} ".BigDecimal::of($qty)->toScale(4);
            }
        }
        if ($day->cash !== null) {
            $plannedCash[] = "{$day->date} ".BigDecimal::of($day->cash[0])->toScale(2);
        }
    }
    expect(count($planned) + count($plannedCash))->toBe(5);
    $variances = DB::table('stock_count_lines')->join('stock_counts', 'stock_counts.id', '=', 'stock_count_lines.count_id')
        ->join('shift_instances', 'shift_instances.id', '=', 'stock_counts.shift_instance_id')->join('stations', 'stations.id', '=', 'shift_instances.station_id')
        ->join('items', 'items.id', '=', 'stock_count_lines.item_id')
        ->where('stock_counts.branch_id', $b)->where('stock_count_lines.variance_qty', '<>', 0)->where('shift_instances.date', '>', $anchor)
        ->get(['shift_instances.date', 'stations.code', 'items.sku', 'stock_count_lines.variance_qty'])
        ->map(fn (object $r): string => "{$r->date} {$r->code} {$r->sku} {$r->variance_qty}")->sort()->values()->all();
    sort($planned);
    expect($variances)->toBe($planned);
    $cash = DB::table('cash_declarations')->join('shift_instances', 'shift_instances.id', '=', 'cash_declarations.shift_instance_id')
        ->where('cash_declarations.branch_id', $b)->where('cash_declarations.variance_total', '<>', 0)->where('shift_instances.date', '>', $anchor)
        ->get(['shift_instances.date', 'cash_declarations.variance_total'])->map(fn (object $r): string => "{$r->date} {$r->variance_total}")->sort()->values()->all();
    sort($plannedCash);
    expect($cash)->toBe($plannedCash);

    // the three planned stock-outs: each opened its automatic production request that morning, and the baker made it
    $stockOuts = collect($plan)->filter(fn (PlannedDay $d): bool => $d->stockOut !== null);
    expect($stockOuts)->toHaveCount(3);
    $requests = DB::table('production_requests')->join('items', 'items.id', '=', 'production_requests.product_item_id')
        ->where('production_requests.branch_id', $b)->where('production_requests.reason', 'stock_out')->get(['production_requests.*', 'items.sku']);
    expect($requests)->toHaveCount(3);
    foreach ($stockOuts as $day) {
        [$from, $to] = p4Bounds($day->date);
        $request = $requests->where('sku', $day->stockOut)->where('created_at', '>=', $from)->where('created_at', '<', $to);
        expect($request)->toHaveCount(1, "stock-out {$day->date}")->and($request->first()->status)->toBe('done');
    }

    // the summaries: every day from the anchor to today rebuilt by the seed; channel totals and revenue equal the raw rows
    $days = DB::table('analytics_days')->where('branch_id', $b)->orderBy('day')->get();
    expect($days->pluck('trigger')->unique()->values()->all())->toBe(['seed'])
        ->and(substr((string) $days->first()->day, 0, 10))->toBe($anchor)->and(substr((string) $days->last()->day, 0, 10))->toBe($today)
        ->and($days)->toHaveCount(P4_DAYS + 3);
    foreach ($days as $built) {
        $day = substr((string) $built->day, 0, 10);
        [$from, $to] = p4Bounds($day);
        $byChannel = DB::table('payments')->where('branch_id', $b)->where('status', '<>', 'voided')->where('received_at', '>=', $from)->where('received_at', '<', $to)->get()->groupBy('channel_id');
        $totals = DB::table('daily_channel_totals')->where('branch_id', $b)->where('day', $day)->get()->keyBy('channel_id');
        expect($totals->keys()->sort()->values()->all())->toBe($byChannel->keys()->sort()->values()->all(), "channels {$day}");
        foreach ($byChannel as $channel => $rows) {
            expect($totals[$channel]->amount)->toBe(p4Sum($rows->pluck('amount')), "amount {$day} {$channel}")
                ->and((int) $totals[$channel]->receipts_count)->toBe($rows->count());
        }
        $settled = DB::table('orders')->where('branch_id', $b)->whereIn('status', ['paid', 'closed'])->where('settled_at', '>=', $from)->where('settled_at', '<', $to)->get();
        $sales = DB::table('daily_sales')->where('branch_id', $b)->where('day', $day)->first();
        expect($sales->revenue)->toBe(p4Sum($settled->pluck('total')), "revenue {$day}")->and((int) $sales->orders_count)->toBe($settled->count());
    }

    // the money adds up: payments less payment refunds equal the paid orders' totals less what was refunded (no refunds yet; the
    // orders.refunded_total column arrives with step 4.1b, so a missing one counts as zero)
    $payments = p4Sum(DB::table('payments')->where('branch_id', $b)->where('status', '<>', 'voided')->pluck('amount'));
    $refunds = p4Sum(DB::table('payment_refunds')->where('branch_id', $b)->pluck('amount'));
    $orders = DB::table('orders')->where('branch_id', $b)->whereIn('status', ['paid', 'closed'])->get();
    expect((string) BigDecimal::of($payments)->minus($refunds)->toScale(2))->toBe((string) BigDecimal::of(p4Sum($orders->pluck('total')))->minus(p4Sum($orders->map(fn (object $o): string => (string) ($o->refunded_total ?? '0.00'))))->toScale(2))
        ->and(DB::table('payment_refunds')->where('branch_id', $b)->count())->toBe(0);

    // receipt settings saved; today's sample orders taken in real time
    expect(DB::table('receipt_settings')->where('branch_id', $b)->count())->toBe(1)
        ->and(DB::table('orders')->where('branch_id', $b)->where('business_date', $today)->count())->toBeGreaterThan(0)
        ->and(DB::table('payment_channels')->where('branch_id', $b)->count())->toBe(8);

    // a second run writes nothing, not even an audit row
    $first = p4Rows($b);
    test()->seed(DemoSeeder::class);
    expect(p4Rows($b))->toBe($first);
});

it('refuses a branch that is not a demo branch (the E2E Test Branch), writing nothing into it', function () {
    $e2e = Branch::factory()->create(['name' => 'E2E Test Branch']);

    $exit = Artisan::call('bakery:seed-demo', ['--branch' => 'E2E Test Branch', '--phase' => '4']);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('not a demo branch');
    foreach (['orders', 'payments', 'stock_movements', 'shift_instances', 'analytics_days', 'stored_files', 'audit_log'] as $table) {
        expect(DB::table($table)->where('branch_id', $e2e->id)->count())->toBe(0, $table);
    }
});

it('refuses a run with no demo branch behind it', function () {
    expect(fn () => $this->seed(Phase4Seeder::class))->toThrow(DemoSeedRefused::class);
});

it('writes no history into a demo branch that holds data: it says so, adds nothing to the ledger, makes sure of the channels and receipt settings and builds the days not built', function () {
    // the live demo: loaded before Phase 4, and its days never summarised
    config(['queue.default' => 'database']);
    putenv('SEED_PHASE=3');
    $this->seed(DemoSeeder::class);
    $branch = p4Branch();
    $b = $branch->id;
    foreach (['daily_sales', 'daily_hour_sales', 'daily_channel_totals', 'daily_item_metrics', 'daily_station_metrics', 'daily_user_metrics', 'analytics_days'] as $table) {
        DB::table($table)->where('branch_id', $b)->delete();
    }
    $ledger = [DB::table('stock_movements')->where('branch_id', $b)->count(), (int) DB::table('stock_movements')->max('id')];
    $orders = DB::table('orders')->where('branch_id', $b)->count();
    $payments = DB::table('payments')->where('branch_id', $b)->count();

    $exit = Artisan::call('bakery:seed-demo', ['--phase' => '4']);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toContain('history not generated: the demo branch already has data; load a new demo branch')
        ->and([DB::table('stock_movements')->where('branch_id', $b)->count(), (int) DB::table('stock_movements')->max('id')])->toBe($ledger)
        ->and(DB::table('orders')->where('branch_id', $b)->count())->toBe($orders)
        ->and(DB::table('payments')->where('branch_id', $b)->count())->toBe($payments)
        ->and(DB::table('payment_channels')->where('branch_id', $b)->count())->toBe(8)
        ->and(DB::table('receipt_settings')->where('branch_id', $b)->count())->toBe(1)
        ->and(DB::table('stored_files')->where('branch_id', $b)->count())->toBe(0);

    // every local day with data is built by the seed (read-only on the ledger), and nothing is dated in the past by this run
    $zone = $branch->timezone;
    $first = CarbonImmutable::parse((string) DB::table('stock_movements')->where('branch_id', $b)->min('created_at'), 'UTC')->setTimezone($zone)->toDateString();
    $built = DB::table('analytics_days')->where('branch_id', $b)->orderBy('day')->get();
    expect($built->pluck('trigger')->unique()->values()->all())->toBe(['seed'])
        ->and(substr((string) $built->first()->day, 0, 10))->toBe($first)
        ->and(substr((string) $built->last()->day, 0, 10))->toBe(CarbonImmutable::now($zone)->toDateString());

    // a second run writes nothing
    $rows = p4Rows($b);
    expect(Artisan::call('bakery:seed-demo', ['--phase' => '4']))->toBe(0)->and(p4Rows($b))->toBe($rows);
});
