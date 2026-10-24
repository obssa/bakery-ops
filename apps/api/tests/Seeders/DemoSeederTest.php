<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Database\Seeders\Phase1Seeder;
use Database\Seeders\Phase2Seeder;
use Database\Seeders\Phase3Seeder;
use Database\Seeders\Phase4Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * testing-strategy.md, Seeds: `DemoSeeder` composes Phase0Seeder .. PhaseNSeeder up to SEED_PHASE (default: every
 * phase seeder that exists), so a phase's e2e job seeds exactly the world its gate expects and later phases extend it.
 * CI's e2e job runs `SEED_PHASE=<n> php artisan migrate:fresh --seed --seeder=DemoSeeder` on a database without roles.
 */

afterEach(function () {
    putenv('SEED_PHASE');
});

it('lists the phase seeders that exist, in phase order, up to the requested phase', function () {
    expect(DemoSeeder::seedersUpTo(0))->toBe([Phase0Seeder::class])
        ->and(DemoSeeder::seedersUpTo(1))->toBe([Phase0Seeder::class, Phase1Seeder::class])
        ->and(DemoSeeder::seedersUpTo(2))->toBe([Phase0Seeder::class, Phase1Seeder::class, Phase2Seeder::class])
        ->and(DemoSeeder::seedersUpTo(3))->toBe([Phase0Seeder::class, Phase1Seeder::class, Phase2Seeder::class, Phase3Seeder::class])
        ->and(DemoSeeder::seedersUpTo(4))->toBe([Phase0Seeder::class, Phase1Seeder::class, Phase2Seeder::class, Phase3Seeder::class, Phase4Seeder::class])
        ->and(DemoSeeder::seedersUpTo(5)[0])->toBe(Phase0Seeder::class)
        ->and(DemoSeeder::seedersUpTo(-1))->toBe([]);
    foreach (DemoSeeder::seedersUpTo(5) as $class) {
        expect(class_exists($class))->toBeTrue()->and($class)->toMatch('/\\\\Phase[0-5]Seeder$/');
    }
})->group('feature', 'seeds');

it('reads the last phase from SEED_PHASE, every phase when it is not set', function () {
    expect(DemoSeeder::maxPhase())->toBe(5);
    putenv('SEED_PHASE=0');
    expect(DemoSeeder::maxPhase())->toBe(0);
})->group('feature', 'seeds');

it('seeds the demo bakery on a database without roles, as the e2e job does with SEED_PHASE=0', function () {
    putenv('SEED_PHASE=0');
    expect(Role::query()->count())->toBe(0);

    $this->seed(DemoSeeder::class);

    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    expect(Role::query()->count())->toBe(8)
        ->and(Item::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->count())->toBeGreaterThan(60);
    // e2e-p0 sees no Phase 1 data
    foreach (['dining_tables', 'devices', 'orders', 'payments'] as $table) {
        expect(DB::table($table)->where('branch_id', $branch->id)->count())->toBe(0, $table);
    }
    expect(User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->count())->toBe(9);
})->group('feature', 'seeds');

/**
 * Row counts of the demo branch in the tables Phase 1 writes (plus the ledger and the audit log) and in the Phase 0 ones.
 *
 * @return array<string, int>
 */
function demoRows(int $branchId, bool $phaseZeroTables = false): array
{
    $tables = ['dining_tables', 'payment_channels', 'devices', 'users', 'orders', 'order_lines', 'order_line_modifiers', 'order_events', 'order_counters', 'payments', 'stock_movements', 'stock_balances', 'audit_log'];
    if ($phaseZeroTables) {
        $tables = ['stations', 'locations', 'categories', 'items', 'item_prices', 'recipes', 'recipe_lines'];
    }
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = DB::table($table)->where('branch_id', $branchId)->count();
    }

    return $rows;
}

it('composes Phase 1 on Phase 0: tables, the eight channels, the shared pad and the sample orders of today, all through module code', function () {
    putenv('SEED_PHASE=1');
    $this->seed(DemoSeeder::class);

    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $scoped = fn (string $model) => $model::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id);

    // 12 dining tables: T1-T10 inside, Terrace 1-2, bilingual, with seats
    $tables = $scoped(DiningTable::class)->orderBy('sort')->get();
    expect($tables)->toHaveCount(12)
        ->and($tables->pluck('code')->all())->toBe(['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'TR1', 'TR2'])
        ->and($tables->every(fn (DiningTable $t) => $t->name_en !== '' && $t->name_am !== '' && $t->seats > 0))->toBeTrue()
        ->and($tables->last()->name_en)->toBe('Terrace 2');

    // the eight payment channels, cash among them
    expect($scoped(PaymentChannel::class)->count())->toBe(8)
        ->and($scoped(PaymentChannel::class)->where('code', 'cash')->exists())->toBeTrue();

    // the shared pad: an enrolled device and the account the tablet signs in with (a waiter); the two waiters exist from Phase 0
    $pad = $scoped(User::class)->where('phone', DemoBakery::PAD_PHONE)->sole();
    expect($pad->hasRole('waiter'))->toBeTrue()
        ->and($pad->name)->toContain('Pad 1')
        ->and($scoped(Device::class)->where('name', 'Front pad')->whereNull('revoked_at')->count())->toBe(1)
        ->and($scoped(User::class)->count())->toBe(10);

    // today's sample orders, by table
    $orders = $scoped(Order::class)->get()->keyBy('table_code');
    expect($orders)->toHaveCount(7)
        ->and($orders->map(fn (Order $o) => $o->status->value)->sortKeys()->all())->toBe([
            'T1' => 'sent', 'T2' => 'sent', 'T3' => 'open', 'T4' => 'in_progress', 'T5' => 'ready', 'T6' => 'served', 'T7' => 'closed',
        ])
        ->and($orders['T3']->order_no)->toBeNull()
        ->and($orders['T7']->paid_total->equals($orders['T7']->total))->toBeTrue();

    // the ledger holds what they did: reservations for the sent lines, consumption for the ready ones, a sale for the
    // croissant of the paid order; and the paid order's one cash payment
    $reasons = DB::table('stock_movements')->where('branch_id', $branch->id)->whereNotNull('reservation_ref')->select('reason', DB::raw('count(*) as n'))->groupBy('reason')->pluck('n', 'reason')->all();
    expect($reasons)->toHaveKeys(['reservation', 'production_consume', 'sale'])->and((int) $reasons['sale'])->toBe(1);
    $payment = DB::table('payments')->where('branch_id', $branch->id)->sole();
    expect((int) $payment->order_id)->toBe($orders['T7']->id);
})->group('feature', 'seeds');

it('runs Phase 1 twice and the second run writes nothing, not even an audit row', function () {
    putenv('SEED_PHASE=1');
    $this->seed(DemoSeeder::class);
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $first = demoRows($branch->id);
    expect($first['orders'])->toBe(7)->and($first['dining_tables'])->toBe(12)->and($first['payment_channels'])->toBe(8);

    $this->seed(DemoSeeder::class);

    expect(demoRows($branch->id))->toBe($first);
})->group('feature', 'seeds');

it('adds to a branch that already holds Phase 0 only what Phase 1 brings (the live demo bakery)', function () {
    putenv('SEED_PHASE=0');
    $this->seed(DemoSeeder::class);
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $phaseZero = demoRows($branch->id);
    $catalog = demoRows($branch->id, true);

    putenv('SEED_PHASE=1');
    $this->seed(DemoSeeder::class);

    expect(demoRows($branch->id, true))->toBe($catalog)
        ->and(demoRows($branch->id)['users'])->toBe($phaseZero['users'] + 1)
        ->and(demoRows($branch->id)['orders'])->toBe(7);
})->group('feature', 'seeds');

/**
 * Row counts of the demo branch in the tables Phase 2 writes, on top of demoRows().
 *
 * @return array<string, int>
 */
function phaseTwoRows(int $branchId): array
{
    $rows = demoRows($branchId);
    foreach (['suppliers', 'purchases', 'purchase_lines', 'item_costs', 'item_cost_history', 'reorder_rules', 'transfers', 'production_requests', 'production_batches', 'notifications', 'stock_alert_states', 'recipes', 'recipe_lines', 'item_prices', 'items'] as $table) {
        $rows[$table] = DB::table($table)->where('branch_id', $branchId)->count();
    }

    return $rows;
}

it('composes Phase 2 on Phase 1: suppliers and costs, purchases, reorder rules, the gate product, recipe version 2, transfers and production, through module code', function () {
    putenv('SEED_PHASE=2');
    config(['queue.default' => 'database']); // what the live host runs; the seed runs the queued listeners synchronously and puts this back
    $this->seed(DemoSeeder::class);

    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $rows = fn (string $table) => DB::table($table)->where('branch_id', $branch->id);
    $item = fn (string $sku) => DB::table('items')->where('branch_id', $branch->id)->where('sku', $sku)->first();

    // three suppliers; every ingredient has an opening cost, and the flour's average moved with the purchase
    expect($rows('suppliers')->count())->toBe(3)
        ->and($rows('item_costs')->count())->toBeGreaterThanOrEqual($rows('items')->where('kind', 'ingredient')->count());
    $flour = $item('ING-FLR-WHT');
    $history = $rows('item_cost_history')->where('item_id', $flour->id)->orderBy('id')->get();
    expect($history)->toHaveCount(2)->and($history[1]->cost_before)->toBe($history[0]->cost_after)->and($history[1]->cost_after)->not->toBe($history[1]->cost_before);

    // two confirmed purchases received into the store (a purchase_in per line) and one draft left for a hand
    expect($rows('purchases')->orderBy('id')->pluck('status')->all())->toBe(['confirmed', 'confirmed', 'draft']);
    $lines = DB::table('purchase_lines')->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')->where('purchases.branch_id', $branch->id)->where('purchases.status', 'confirmed');
    expect((clone $lines)->whereNull('purchase_lines.movement_id')->count())->toBe(0)
        ->and($rows('stock_movements')->where('ref_type', 'purchase')->where('reason', 'purchase_in')->count())->toBe((clone $lines)->count());

    // eight reorder rules, and the only open alert is the gate product's own low stock
    $honey = $item('P-CAK-HONEY-SLICE');
    expect($rows('reorder_rules')->count())->toBe(8)->and($rows('reorder_rules')->where('is_active', 1)->count())->toBe(8);
    $open = $rows('stock_alert_states')->where('is_active', 1)->get();
    expect($open)->toHaveCount(1)->and($open[0]->item_id)->toBe($honey->id)->and($open[0]->type)->toBe('low');

    // the gate product: to stock, made at the cake room, 6 a batch, a recipe over 12 slices, 150.00, one slice on hand at the display case
    $display = $rows('locations')->where('type', 'display_case')->first();
    $cakeRoom = $rows('stations')->where('code', 'cake_room')->first();
    expect($honey->fulfilment)->toBe('to_stock')
        ->and($honey->production_station_id)->toBe($cakeRoom->id)
        ->and($honey->default_batch_qty)->toBe('6.0000')
        ->and(DB::table('recipes')->where('product_item_id', $honey->id)->sole()->yield_qty)->toBe('12.0000')
        ->and(DB::table('item_prices')->where('item_id', $honey->id)->sole()->price)->toBe('150.00')
        ->and(stockRow($honey->id, $display->id)->on_hand)->toBe('1.0000')
        ->and(DB::table('stock_movements')->where('item_id', $honey->id)->pluck('reason')->all())->toBe(['count_adjustment']);

    // Bread roll: version 2 is active with a change note, version 1 is still there
    $roll = DB::table('recipes')->where('product_item_id', $item('P-BRD-ROLL')->id)->orderBy('version')->get();
    expect($roll->pluck('version')->map(fn ($v) => (int) $v)->all())->toBe([1, 2])
        ->and($roll->pluck('is_active')->map(fn ($v) => (int) $v)->all())->toBe([0, 1])
        ->and($roll[1]->change_note)->not->toBeNull();

    // three transfers: one confirmed (two ledger rows), one waiting for the store, one rejected with a reason
    $transfers = $rows('transfers')->orderBy('id')->get()->keyBy('status');
    expect($transfers->keys()->sort()->values()->all())->toBe(['confirmed', 'rejected', 'requested'])
        ->and($transfers['rejected']->reject_reason)->not->toBeEmpty()
        ->and(DB::table('stock_movements')->where('ref_type', 'transfer')->where('ref_id', $transfers['confirmed']->id)->count())->toBe(2)
        ->and(DB::table('stock_movements')->where('ref_type', 'transfer')->whereIn('ref_id', [$transfers['requested']->id, $transfers['rejected']->id])->count())->toBe(0);

    // production: a scheduled high-priority request, an accepted one, a batch waiting for sign-off (nothing written yet), one confirmed today
    $requests = $rows('production_requests')->get();
    expect($requests->groupBy('status')->map->count()->all())->toMatchArray(['requested' => 1, 'accepted' => 1, 'pending_confirmation' => 1, 'done' => 1]);
    $baguette = $requests->firstWhere('product_item_id', $item('P-BRD-BAGUETTE')->id);
    expect($baguette->priority)->toBe('high')->and($baguette->reason)->toBe('schedule')->and($baguette->requested_qty)->toBe('80.0000')->and($baguette->due_at)->not->toBeNull();
    $batches = $rows('production_batches')->get()->keyBy('status');
    $batchRows = fn (string $status, string $reason) => DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batches[$status]->id)->where('reason', $reason)->count();
    expect($batches->keys()->sort()->values()->all())->toBe(['confirmed', 'finished'])
        ->and(DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batches['finished']->id)->count())->toBe(0)
        ->and($batchRows('confirmed', 'production_output'))->toBe(1)
        ->and($batchRows('confirmed', 'production_consume'))->toBeGreaterThan(3);

    // the queued listeners ran during the seed: the demo has real inbox rows without a worker, and the queue setting is back
    expect($rows('notifications')->count())->toBeGreaterThan(5)
        ->and(config('queue.default'))->toBe('database', 'the seed restores the queue connection it found')
        ->and($rows('audit_log')->where('action', 'purchase.confirm')->count())->toBe(2);
})->group('feature', 'seeds');

it('runs Phase 2 twice and the second run writes nothing, not even an audit row or an inbox line', function () {
    putenv('SEED_PHASE=2');
    $this->seed(DemoSeeder::class);
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $first = phaseTwoRows($branch->id);
    expect($first['purchases'])->toBe(3)->and($first['transfers'])->toBe(3)->and($first['reorder_rules'])->toBe(8);

    $this->seed(DemoSeeder::class);

    expect(phaseTwoRows($branch->id))->toBe($first);
})->group('feature', 'seeds');

it('adds to a branch that already holds Phases 0 and 1 only what Phase 2 brings (the live demo bakery)', function () {
    putenv('SEED_PHASE=1');
    $this->seed(DemoSeeder::class);
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $before = phaseTwoRows($branch->id);
    $catalog = demoRows($branch->id, true);

    putenv('SEED_PHASE=2');
    $this->seed(DemoSeeder::class);
    $after = phaseTwoRows($branch->id);

    // nothing of Phases 0 and 1 is rewritten: the dining room, the orders, the people, the payments
    foreach (['dining_tables', 'payment_channels', 'devices', 'users', 'orders', 'order_lines', 'order_line_modifiers', 'order_events', 'order_counters', 'payments'] as $table) {
        expect($after[$table])->toBe($before[$table], $table);
    }
    $now = demoRows($branch->id, true);
    expect($now['stations'])->toBe($catalog['stations'])->and($now['locations'])->toBe($catalog['locations'])->and($now['categories'])->toBe($catalog['categories'])
        // one new product with its recipe and price, and one recipe version more for the bread roll
        ->and($now['items'])->toBe($catalog['items'] + 1)
        ->and($now['recipes'])->toBe($catalog['recipes'] + 2)
        ->and($now['item_prices'])->toBe($catalog['item_prices'] + 1)
        ->and($before['purchases'])->toBe(0)
        ->and($after['suppliers'])->toBe(3)->and($after['purchases'])->toBe(3);
})->group('feature', 'seeds');

it('seeds the role matrix and then the demo bakery with `db:seed`', function () {
    $this->seed();

    expect(Role::query()->count())->toBe(8)
        ->and(Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->count())->toBe(1);
})->group('feature', 'seeds');

it('seeds Phases 0 to 2 with the shift rule on, opening one shift at each tracked station and none at the store, and a second run writes nothing', function () {
    config(['ledger.require_shift_for_station_locations' => true]);
    putenv('SEED_PHASE=2');
    config(['queue.default' => 'database']);
    $this->seed(DemoSeeder::class);

    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $stations = DB::table('stations')->where('branch_id', $branch->id)->pluck('tracks_shifts', 'code')->map(fn ($v) => (bool) $v)->all();
    expect($stations)->toBe(['till' => true, 'kitchen' => true, 'bar' => true, 'cake_room' => true, 'bakery' => true, 'store' => false]);

    $shifts = DB::table('shift_instances')->where('shift_instances.branch_id', $branch->id)->join('stations', 'stations.id', '=', 'shift_instances.station_id')->orderBy('shift_instances.id')->get(['stations.code', 'shift_instances.status', 'shift_instances.opening_mode']);
    expect($shifts->pluck('code')->sort()->values()->all())->toBe(['bakery', 'bar', 'cake_room', 'kitchen', 'till'])
        ->and($shifts->every(fn (object $s): bool => $s->status === 'open' && $s->opening_mode === 'carried'))->toBeTrue();
    // the opening stock, the sample orders' consumption and the batches were all written under those shifts
    expect(DB::table('stock_movements')->where('branch_id', $branch->id)->whereNotNull('shift_instance_id')->count())->toBeGreaterThan(0);

    $first = phaseTwoRows($branch->id) + ['shift_instances' => DB::table('shift_instances')->where('branch_id', $branch->id)->count(), 'shift_roster' => DB::table('shift_roster')->count()];
    $this->seed(DemoSeeder::class);

    expect(phaseTwoRows($branch->id) + ['shift_instances' => DB::table('shift_instances')->where('branch_id', $branch->id)->count(), 'shift_roster' => DB::table('shift_roster')->count()])->toBe($first);
})->group('feature', 'seeds');

it('composes Phase 3 on Phase 2 on a fresh branch: the history is locked and chained, the open shifts are those of today, and a second run writes nothing', function () {
    putenv('SEED_PHASE=3');
    $this->seed(DemoSeeder::class);
    $branch = Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->sole();
    $shifts = fn () => DB::table('shift_instances')->where('branch_id', $branch->id)->pluck('status')->countBy()->all();
    $first = [$shifts(), phaseTwoRows($branch->id)];

    expect($first[0])->toEqual(['locked' => 15, 'pending_approval' => 1, 'open' => 5])
        ->and(DB::table('stock_counts')->where('branch_id', $branch->id)->whereNotNull('payload_hash')->count())->toBeGreaterThan(30);

    $this->seed(DemoSeeder::class);

    expect([$shifts(), phaseTwoRows($branch->id)])->toBe($first);
})->group('feature', 'seeds');
