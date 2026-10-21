<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Step 2.6a (plan section 7, the low half of AlertEpisodePropertyTest): random walks of two balances of one item (a store and a
 * kitchen) through stock in, waste, reservations and releases, with a rule for the store and a branch-wide rule that are edited,
 * switched off, deleted and brought back in the middle of the walk. Whatever the walk:
 *   - at most one `low` episode is open for one balance and one `out` episode is open for one balance, never two of a kind;
 *   - a `low` episode is open exactly while its rule is live and active and available (the store's own, or the sum over the
 *     branch for the branch-wide rule) is at or below the rule's minimum;
 *   - every crossing produces exactly one event: StockLow once per opening, StockRecovered(low) once per closing, and every
 *     cleared episode has a closing, every open one has none.
 * 30 seeds, at most 60 operations each; a failure prints the seed and the trail; LOW_ALERT_SEED=<n> replays one.
 */

/** @return array<string, array{0: int}> */
function lowAlertSeeds(): array
{
    $one = getenv('LOW_ALERT_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 4001 + $i * 7919, range(1, 30));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

it('opens and closes low episodes exactly as available crosses the minimum, whatever the walk and the edits of the rules', function (int $seed) {
    fwrite(STDERR, "[low alert property] seed {$seed}\n");
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    $b = $branch->id;
    seedRoles();
    withActor(makeUser($branch, 'admin')->id);
    $store = stockLocation($branch, LocationType::Store);
    $kitchen = stockLocation($branch, LocationType::Kitchen);
    $flour = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $reservations = app(Reservations::class);
    Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);

    $makeRule = fn (?int $place, int $min): ReorderRule => ReorderRule::factory()->create([
        'branch_id' => $b, 'item_id' => $flour->id, 'location_id' => $place, 'location_key' => $place ?? 0, 'min_qty' => (string) $min, 'reorder_qty' => '10',
    ]);
    $rules = ['store' => $makeRule($store->id, $rng->getInt(0, 8)), 'wide' => $makeRule(null, $rng->getInt(0, 12))];
    $keys = ['store' => $store->id, 'wide' => 0];

    $available = function (int $place) use ($flour): BigDecimal {
        $row = stockRow($flour, $place);

        return $row === null ? BigDecimal::zero() : BigDecimal::of((string) $row->on_hand)->minus((string) $row->reserved);
    };
    // what each rule key should be: open (true) or not (false), from the live rule and the balances
    $expected = function () use (&$rules, $available, $store, $kitchen): array {
        $state = [];
        foreach (['store' => $available($store->id), 'wide' => $available($store->id)->plus($available($kitchen->id))] as $name => $quantity) {
            $rule = ReorderRule::query()->find($rules[$name]->id);
            $state[$name] = $rule !== null && $rule->is_active && $quantity->isLessThanOrEqualTo($rule->min_qty->toBigDecimal());
        }

        return $state;
    };
    $evaluate = fn () => app()->call([new EvaluateBalance($b, $flour->id, $store->id), 'handle']);

    $open = []; // reservation ref => quantity (all at the store)
    $opens = 0;
    $closes = 0;
    $trail = [];
    stockIn($flour, $store, (string) $rng->getInt(1, 6));
    $evaluate();
    $was = $expected();
    $opens += (int) $was['store'] + (int) $was['wide'];

    $operations = $rng->getInt(15, 60);
    for ($i = 0; $i < $operations; $i++) {
        $choice = $rng->getInt(1, 10);
        $place = $rng->getInt(0, 1) === 0 ? $store : $kitchen;
        $row = stockRow($flour, $place);
        $onHand = $row === null ? BigDecimal::zero() : BigDecimal::of((string) $row->on_hand);
        $free = $available($place->id);
        $note = "op {$i}: ";
        switch (true) {
            case $choice <= 3:
                $qty = (string) $rng->getInt(1, 6);
                stockIn($flour, $place, $qty, MovementReason::PurchaseIn);
                $note .= "in {$qty} at {$place->type->value}";
                break;
            case $choice === 4 && $onHand->isGreaterThan(0) && $free->isGreaterThan(0):
                $qty = (string) $rng->getInt(1, (int) (string) $free->toScale(0, RoundingMode::Down));
                stockIn($flour, $place, '-'.$qty, MovementReason::Waste);
                $note .= "waste {$qty} at {$place->type->value}";
                break;
            case $choice === 5 && $available($store->id)->isGreaterThan(0):
                $qty = (string) $rng->getInt(1, (int) (string) $available($store->id)->toScale(0, RoundingMode::Down));
                $ref = (string) Str::uuid();
                $reservations->reserve([new ReserveLine($flour->id, $qty, $store->id)], $ref);
                $open[$ref] = $qty;
                $note .= "reserve {$qty}";
                break;
            case $choice === 6 && $open !== []:
                $ref = array_keys($open)[$rng->getInt(0, count($open) - 1)];
                $reservations->release($ref);
                $note .= "release {$open[$ref]}";
                unset($open[$ref]);
                break;
            case $choice === 7:
                $name = $rng->getInt(0, 1) === 0 ? 'store' : 'wide';
                $min = $rng->getInt(0, 12);
                $rules[$name]->forceFill(['min_qty' => (string) $min])->save();
                $evaluate();
                $note .= "min of {$name} rule := {$min}";
                break;
            case $choice === 8:
                $name = $rng->getInt(0, 1) === 0 ? 'store' : 'wide';
                $on = ! (bool) ReorderRule::query()->find($rules[$name]->id)?->is_active;
                ReorderRule::query()->whereKey($rules[$name]->id)->update(['is_active' => $on]);
                $evaluate();
                $note .= "{$name} rule active := ".($on ? 'yes' : 'no');
                break;
            case $choice === 9:
                $name = $rng->getInt(0, 1) === 0 ? 'store' : 'wide';
                $live = ReorderRule::query()->find($rules[$name]->id);
                if ($live !== null) {
                    $live->delete();
                    $note .= "{$name} rule deleted";
                } else {
                    $rules[$name] = $makeRule($name === 'store' ? $store->id : null, $rng->getInt(0, 12));
                    $note .= "{$name} rule made again";
                }
                $evaluate();
                break;
            default:
                $note .= 'skipped';
        }
        $trail[] = $note;

        $now = $expected();
        foreach (['store', 'wide'] as $name) {
            $opens += (int) ($now[$name] && ! $was[$name]);
            $closes += (int) (! $now[$name] && $was[$name]);
        }
        $was = $now;

        $context = "seed {$seed} (replay: LOW_ALERT_SEED={$seed})\n".implode("\n", $trail);
        foreach (['low', 'out'] as $type) {
            foreach ($keys as $key) {
                expect(DB::table('stock_alert_states')->where('item_id', $flour->id)->where('type', $type)->where('location_key', $key)->where('is_active', 1)->count())->toBeLessThanOrEqual(1, $context);
            }
        }
        foreach ($keys as $name => $key) {
            $isOpen = DB::table('stock_alert_states')->where('item_id', $flour->id)->where('type', 'low')->where('location_key', $key)->where('is_active', 1)->exists();
            expect($isOpen)->toBe($now[$name], "{$name} episode vs the rule and the balance\n{$context}");
        }
        $low = DB::table('stock_alert_states')->where('item_id', $flour->id)->where('type', 'low')->get();
        expect($low->count())->toBe($opens, $context)
            ->and($low->whereNotNull('is_active')->count())->toBe((int) $now['store'] + (int) $now['wide'], $context)
            ->and($low->whereNull('is_active')->every(fn (object $e): bool => $e->cleared_at !== null))->toBeTrue($context);
        Event::assertDispatchedTimes(StockLow::class, $opens);
        $lowRecoveries = Event::dispatched(StockRecovered::class)->filter(fn (array $args): bool => $args[0]->type === 'low')->count();
        expect($lowRecoveries)->toBe($closes, $context);
    }
})->with(lowAlertSeeds())->group('property', 'module:inventory');
