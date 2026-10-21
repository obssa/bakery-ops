<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Models\Branch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6b (plan section 6 item 6): `php artisan inventory:sweep-alerts`, scheduled hourly. It re-queues the unique EvaluateBalance of
 * every balance that has an open episode (low or out) or a live, active reorder rule, so an evaluation that was lost (a job that
 * died, a lock that outlived it) heals within the hour. It reads the rules and episodes in chunks (never every balance at once),
 * works across branches, and is idempotent: the job is unique until it starts, so a second sweep while the first jobs wait adds nothing.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $this->rule = fn (Item $item, ?object $at, string $min = '500', array $over = []): ReorderRule => ReorderRule::factory()->create($over + [
        'branch_id' => $this->branch->id, 'item_id' => $item->id, 'location_id' => $at?->id, 'location_key' => $at?->id ?? 0,
        'min_qty' => $min, 'reorder_qty' => '100', 'notify_roles' => ['admin'],
    ]);
    $this->sweep = fn (): int => Artisan::call('inventory:sweep-alerts');
    $this->pushed = fn (): array => Queue::pushed(EvaluateBalance::class)
        ->map(fn (EvaluateBalance $job): string => "{$job->branchId}:{$job->itemId}:{$job->locationId}")->sort()->values()->all();
    $this->key = fn (Item $item, object $at, ?Branch $branch = null): string => ($branch ?? $this->branch)->id.":{$item->id}:{$at->id}";
});

it('is scheduled hourly', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'inventory:sweep-alerts'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
});

it('heals an evaluation that was lost: the sweep queues it, the worker opens the episode', function () {
    config(['queue.default' => 'database']);
    ($this->rule)($this->flour, $this->store);
    stockIn($this->flour, $this->store, '1000');
    stockIn($this->flour, $this->store, '-700', MovementReason::Waste); // 300 available, at or below the minimum

    // the evaluation queued by the movement is lost: the row is gone and its uniqueness lock (ten minutes) has lapsed
    DB::table('jobs')->delete();
    $this->travel(11)->minutes();
    expect(DB::table('stock_alert_states')->count())->toBe(0);

    expect(($this->sweep)())->toBe(0)->and(DB::table('jobs')->where('payload', 'like', '%EvaluateBalance%')->count())->toBe(1);
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    $episodes = DB::table('stock_alert_states')->where('item_id', $this->flour->id)->get();
    expect($episodes)->toHaveCount(1)
        ->and($episodes[0]->type)->toBe('low')
        ->and($episodes[0]->is_active)->toBe(1)
        ->and($episodes[0]->available_at_start)->toBe('300.0000');
});

it('is idempotent: a second sweep while the first jobs are pending queues nothing new', function () {
    config(['queue.default' => 'database']);
    ($this->rule)($this->flour, $this->store);
    $sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    ($this->rule)($sugar, $this->kitchen, '5');
    stockIn($this->flour, $this->store, '10');
    stockIn($sugar, $this->kitchen, '10');
    DB::table('jobs')->delete();
    $this->travel(11)->minutes();

    ($this->sweep)();
    $evaluations = fn (): int => DB::table('jobs')->where('payload', 'like', '%EvaluateBalance%')->count();
    $afterFirst = $evaluations();
    ($this->sweep)();
    ($this->sweep)();

    expect($afterFirst)->toBe(2)->and($evaluations())->toBe($afterFirst);
});

it('queues a balance with an open episode and one with a live rule, and nothing for a balance with neither', function () {
    Queue::fake();
    $withRule = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $withEpisode = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $neither = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    foreach ([$withRule, $withEpisode, $neither] as $item) {
        stockIn($item, $this->store, '10');
    }
    DB::table('stock_alert_states')->insert([
        'branch_id' => $this->branch->id, 'item_id' => $withEpisode->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id, 'type' => 'out',
        'is_active' => 1, 'active_since' => now(), 'available_at_start' => '0', 'created_at' => now(), 'updated_at' => now(),
    ]);
    ($this->rule)($withRule, $this->store);
    Queue::fake(); // forget what the movements queued

    ($this->sweep)();

    expect(($this->pushed)())->toBe([($this->key)($withRule, $this->store), ($this->key)($withEpisode, $this->store)]);
});

it('leaves out a rule that is switched off or deleted, and a closed episode', function () {
    $off = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $gone = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $closed = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    foreach ([$off, $gone, $closed] as $item) {
        stockIn($item, $this->store, '10');
    }
    ($this->rule)($off, $this->store, '500', ['is_active' => false]);
    ($this->rule)($gone, $this->store)->delete();
    DB::table('stock_alert_states')->insert([
        'branch_id' => $this->branch->id, 'item_id' => $closed->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id, 'type' => 'out',
        'is_active' => null, 'active_since' => now()->subHour(), 'cleared_at' => now(), 'available_at_start' => '0', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Queue::fake();

    ($this->sweep)();

    expect(($this->pushed)())->toBe([]);
});

it('evaluates a branch-wide rule through the lowest-numbered stock place of the item, never the cash drawer, once', function () {
    $drawer = stockLocation($this->branch, LocationType::CashDrawer);
    stockIn($this->flour, $drawer, '5');
    stockIn($this->flour, $this->kitchen, '5');
    stockIn($this->flour, $this->store, '5');
    ($this->rule)($this->flour, null);
    DB::table('stock_alert_states')->insert([ // and its open episode, which names no place
        'branch_id' => $this->branch->id, 'item_id' => $this->flour->id, 'location_id' => null, 'location_key' => 0, 'type' => 'low',
        'is_active' => 1, 'active_since' => now(), 'available_at_start' => '5', 'threshold_qty' => '500', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Queue::fake();

    ($this->sweep)();

    expect(($this->pushed)())->toBe([($this->key)($this->flour, $this->store)]);
});

it('queues a balance once however many rules and episodes point at it', function () {
    Queue::fake();
    ($this->rule)($this->flour, $this->store);
    ($this->rule)($this->flour, null);
    stockIn($this->flour, $this->store, '5');
    DB::table('stock_alert_states')->insert([
        'branch_id' => $this->branch->id, 'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id, 'type' => 'low',
        'is_active' => 1, 'active_since' => now(), 'available_at_start' => '5', 'threshold_qty' => '500', 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->sweep)();

    expect(($this->pushed)())->toBe([($this->key)($this->flour, $this->store)]);
});

it('covers every branch and every chunk', function () {
    $other = Branch::factory()->create();
    $otherStore = stockLocation($other, LocationType::Store);
    $expected = [];
    foreach (range(1, 7) as $i) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        stockIn($item, $this->store, '10');
        ($this->rule)($item, $this->store);
        $expected[] = ($this->key)($item, $this->store);
    }
    foreach (range(1, 4) as $i) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $other->id]);
        ReorderRule::factory()->create(['branch_id' => $other->id, 'item_id' => $item->id, 'location_id' => $otherStore->id, 'location_key' => $otherStore->id, 'min_qty' => '5', 'reorder_qty' => '5', 'notify_roles' => ['admin']]);
        $expected[] = ($this->key)($item, $otherStore, $other);
    }
    sort($expected);
    Queue::fake();

    Artisan::call('inventory:sweep-alerts', ['--chunk' => 3]);

    expect(($this->pushed)())->toBe($expected);
});

it('reads rules and episodes a chunk at a time', function () {
    foreach (range(1, 9) as $i) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        ($this->rule)($item, $this->store);
    }
    Queue::fake();
    $selects = 0;
    DB::listen(function ($q) use (&$selects): void {
        if (str_contains($q->sql, 'reorder_rules') && str_starts_with(strtolower($q->sql), 'select')) {
            $selects++;
        }
    });

    Artisan::call('inventory:sweep-alerts', ['--chunk' => 2]);

    expect($selects)->toBeGreaterThanOrEqual(5);
});

it('rejects a chunk size that is not a positive number', function () {
    expect(Artisan::call('inventory:sweep-alerts', ['--chunk' => 0]))->toBe(2);
});

it('reports how many evaluations it queued', function () {
    ($this->rule)($this->flour, $this->store);
    stockIn($this->flour, $this->store, '5');
    Queue::fake();

    $this->artisan('inventory:sweep-alerts')->expectsOutputToContain('1')->assertSuccessful();
});
