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

it('seeds the role matrix and then the demo bakery with `db:seed`', function () {
    $this->seed();

    expect(Role::query()->count())->toBe(8)
        ->and(Branch::query()->where('name', DemoBakery::DEFAULT_BRANCH)->count())->toBe(1);
})->group('feature', 'seeds');
