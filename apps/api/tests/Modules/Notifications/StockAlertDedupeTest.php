<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

pest()->group('module:notifications', 'module:inventory', 'feature');

/*
 * Step 2.6a, ADR 0011 (alert dedupe), end to end on the synchronous queue: a real balance walks through real movements, the
 * evaluator opens and closes the episodes, and the Notifications listeners write the inbox through their delivery gates.
 * What the dedupe promises: one `low` line per person per episode and one `out` line per person per episode, in whatever order
 * the balance falls and recovers, and again after every recovery.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    withActor($this->admin->id);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->rule = ReorderRule::factory()->create([
        'branch_id' => $this->branch->id, 'item_id' => $this->flour->id, 'location_id' => $this->store->id, 'location_key' => $this->store->id,
        'min_qty' => '500', 'reorder_qty' => '2000', 'notify_roles' => ['admin', 'manager'],
    ]);
    $this->waste = fn (string $qty) => stockIn($this->flour, $this->store, '-'.$qty, MovementReason::Waste);
    $this->receive = fn (string $qty) => stockIn($this->flour, $this->store, $qty);
    $this->lines = fn (string $type) => Notification::query()->where('type', $type)->count();
    $this->gate = fn (string $type) => DB::table('notification_dedupe')->where('dedupe_key', "{$type}:{$this->flour->id}:{$this->store->id}")->where('is_active', 1)->exists();
    ($this->receive)('1000');
});

it('fires low once and out once when a balance falls to low, then to out, and recovers', function () {
    ($this->waste)('600');   // 400: low
    expect(($this->lines)('stock_low'))->toBe(2)->and(($this->lines)('stock_out'))->toBe(0)->and(($this->gate)('stock_low'))->toBeTrue();

    ($this->waste)('100');   // 300: still low, nothing new
    ($this->waste)('300');   // 0: out
    expect(($this->lines)('stock_low'))->toBe(2, 'the low alert is not repeated by the stock-out')
        ->and(($this->lines)('stock_out'))->toBe(2)
        ->and(($this->gate)('stock_out'))->toBeTrue();

    ($this->receive)('1000'); // 1000: both recover
    expect(($this->gate)('stock_low'))->toBeFalse()->and(($this->gate)('stock_out'))->toBeFalse()
        ->and(($this->lines)('stock_low'))->toBe(2)->and(($this->lines)('stock_out'))->toBe(2, 'a recovery tells nobody');
    expect(DB::table('stock_alert_states')->where('item_id', $this->flour->id)->where('is_active', 1)->count())->toBe(0);
});

it('tells both alerts once when the balance falls from fine to out in a single movement', function () {
    ($this->waste)('1000');

    expect(($this->lines)('stock_low'))->toBe(2)->and(($this->lines)('stock_out'))->toBe(2);
});

it('tells again after every recovery, and not while the balance only moves inside an episode', function () {
    ($this->waste)('600');    // 400 low (told)
    ($this->receive)('50');   // 450 still low
    ($this->waste)('100');    // 350 still low
    expect(($this->lines)('stock_low'))->toBe(2);

    ($this->receive)('400');  // 750 above the minimum: recovered
    ($this->waste)('250');    // 500: at the minimum is low again (told again)
    expect(($this->lines)('stock_low'))->toBe(4)
        ->and(Notification::query()->where('type', 'stock_low')->distinct()->count('dedupe_key'))->toBe(2, 'one inbox key per episode');

    ($this->receive)('1');    // 501 recovered
    ($this->waste)('1');      // 500 low again
    expect(($this->lines)('stock_low'))->toBe(6, 'no hysteresis: the plan default tells at every crossing');
});

it('does not tell a low alert for a rule that is off or for a balance above its minimum', function () {
    $this->rule->forceFill(['is_active' => false])->save();
    ($this->waste)('900');
    expect(($this->lines)('stock_low'))->toBe(0);

    $this->rule->forceFill(['is_active' => true])->save();
    ($this->receive)('2000');
    expect(($this->lines)('stock_low'))->toBe(0);
});
