<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Enums\StationCode;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Step 2.1 (F1, plan section 7, AlertEpisodePropertyTest): random walks of one balance through stock in, reservations,
 * releases, sales, waste and production output. Whatever the walk, the episodes alternate open / close, never two are
 * open for one key, an episode is open exactly while available is 0 or below, StockOut fired once per fall to 0 or below
 * from above and StockRecovered once per rise above 0 from below. 30 seeds, at most 50 operations each;
 * ALERT_EPISODE_SEED=<n> replays one.
 */

/** @return array<string, array{0: int}> */
function alertEpisodeSeeds(): array
{
    $one = getenv('ALERT_EPISODE_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 9001 + $i * 7919, range(1, 30));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

it('opens and closes episodes exactly as available crosses 0, whatever the walk', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    $b = $branch->id;
    seedRoles();
    withActor(makeUser($branch, 'admin')->id);
    $display = stockLocation($branch, LocationType::DisplayCase, Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]));
    $cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $b]);
    $reservations = app(Reservations::class);
    Event::fake([StockOut::class, StockRecovered::class]);

    $balance = function () use ($cake, $display): array {
        $row = stockRow($cake, $display);

        return [BigDecimal::of((string) $row->on_hand), BigDecimal::of((string) $row->reserved)];
    };
    $open = []; // reservation ref => quantity
    $outs = 0;
    $recoveries = 0;
    $trail = [];

    stockIn($cake, $display, '3');
    $wasOut = false;
    $operations = $rng->getInt(10, 50);
    for ($i = 0; $i < $operations; $i++) {
        [$onHand, $reserved] = $balance();
        $available = $onHand->minus($reserved);
        $choice = $rng->getInt(1, 6);
        $note = "op {$i}: ";
        switch (true) {
            case $choice === 1 || $choice === 2:
                $qty = (string) $rng->getInt(1, 5);
                stockIn($cake, $display, $qty, MovementReason::ProductionOutput);
                $note .= "in {$qty}";
                break;
            case $choice === 3 && $available->isGreaterThan(0):
                $qty = (string) $rng->getInt(1, (int) (string) $available->toScale(0, RoundingMode::DOWN));
                $ref = (string) Str::uuid();
                $reservations->reserve([new ReserveLine($cake->id, $qty, $display->id)], $ref);
                $open[$ref] = $qty;
                $note .= "reserve {$qty}";
                break;
            case $choice === 4 && $open !== []:
                $ref = array_keys($open)[$rng->getInt(0, count($open) - 1)];
                $reservations->release($ref);
                $note .= "release {$open[$ref]}";
                unset($open[$ref]);
                break;
            case $choice === 5 && $open !== []:
                $ref = array_keys($open)[$rng->getInt(0, count($open) - 1)];
                $reservations->consume($ref, null, MovementReason::Sale);
                $note .= "sell {$open[$ref]}";
                unset($open[$ref]);
                break;
            case $choice === 6 && $onHand->isGreaterThan(0):
                $qty = (string) $rng->getInt(1, (int) (string) $onHand->toScale(0, RoundingMode::DOWN));
                stockIn($cake, $display, '-'.$qty, MovementReason::Waste);
                $note .= "waste {$qty}";
                break;
            default:
                $note .= 'skipped';
        }
        $trail[] = $note;

        [$onHand, $reserved] = $balance();
        $isOut = $onHand->minus($reserved)->isLessThanOrEqualTo(0);
        $outs += $isOut && ! $wasOut ? 1 : 0;
        $recoveries += ! $isOut && $wasOut ? 1 : 0;
        $wasOut = $isOut;

        $episodes = DB::table('stock_alert_states')->where('item_id', $cake->id)->orderBy('id')->get();
        $context = "seed {$seed}\n".implode("\n", $trail);
        expect($episodes->where('is_active', 1)->count())->toBe($isOut ? 1 : 0, $context)
            ->and($episodes->count())->toBe($outs, $context)
            ->and($episodes->where('type', '!=', 'out')->count())->toBe(0, $context)
            ->and($episodes->slice(0, -1)->every(fn (object $e): bool => $e->is_active === null && $e->cleared_at !== null))->toBeTrue($context);
        Event::assertDispatchedTimes(StockOut::class, $outs);
        Event::assertDispatchedTimes(StockRecovered::class, $recoveries);
    }
})->with(alertEpisodeSeeds())->group('property', 'module:inventory');
