<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Phase 0 gate, criterion 1 (manifest phases["0"].gate; ADR 0002): 200 seeded random movement sequences (at most 50
 * operations each) over 5 items x 3 locations keep
 *
 *     sum(stock_movements) == stock_balances == item_stock_view
 *
 * and both equal an independent model kept by this test, which also predicts which operations the Ledger must
 * refuse (on_hand or reserved below zero) so that a refused batch is shown to leave nothing behind (all-or-nothing).
 * Operations: single movements of every reason (reservations with and without their consumption), batches of 2 to 4
 * movements, and transfers. Pest has no shrinking: the dataset name and every failure message carry the seed;
 * LEDGER_PROPERTY_SEED=<n> replays one sequence.
 */

uses(RefreshDatabase::class);

/** @return array<string, array{0: int}> */
function ledgerPropertySeeds(): array
{
    $one = getenv('LEDGER_PROPERTY_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 20261019 + $i * 7919, range(1, 200));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

/** A positive 4 dp quantity up to $max units, from an integer draw (no PHP float ever). */
function propertyQty(Randomizer $rng, int $max): string
{
    $tenThousandths = $rng->getInt(1, $max * 10000);

    return sprintf('%d.%04d', intdiv($tenThousandths, 10000), $tenThousandths % 10000);
}

it('keeps sum(movements) == stock_balances == item_stock_view over random sequences', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $branch = actInBranch(Branch::factory()->create());
    withActor(User::factory()->create(['branch_id' => $branch->id])->id);
    $items = array_map(fn (): int => Item::factory()->ingredient('g')->create(['branch_id' => $branch->id])->id, range(1, 5));
    $locations = [
        stockLocation($branch, LocationType::Store)->id,
        stockLocation($branch, LocationType::Kitchen)->id,
        stockLocation($branch, LocationType::DisplayCase)->id,
    ];
    $ledger = app(Ledger::class);
    $refs = [];

    /** the model: "item:location" => [on_hand, reserved] as BigDecimal */
    $model = [];
    $written = 0;

    // one random movement of any reason; may be one the Ledger has to refuse
    $randomIntent = function () use ($rng, $items, $locations, &$refs): array {
        $item = $items[$rng->getInt(0, 4)];
        $location = $locations[$rng->getInt(0, 2)];
        $ref = function () use ($rng, &$refs): string {
            if ($refs === [] || $rng->getInt(0, 2) === 0) {
                $refs[] = sprintf('%08x-%04x-4%03x-a%03x-%012x', $rng->getInt(0, 0x7FFFFFFF), $rng->getInt(0, 0xFFFF), $rng->getInt(0, 0xFFF), $rng->getInt(0, 0xFFF), $rng->getInt(0, 0x7FFFFFFF));
            }

            return $refs[$rng->getInt(0, count($refs) - 1)];
        };

        return match ($rng->getInt(0, 11)) {
            0, 1 => [MovementIntent::of($item, $location, propertyQty($rng, 50), MovementReason::PurchaseIn, 'property')],
            2 => [MovementIntent::of($item, $location, propertyQty($rng, 20), MovementReason::ProductionOutput, 'property')],
            3 => [MovementIntent::of($item, $location, propertyQty($rng, 5), MovementReason::Returned, 'property')],
            4 => [MovementIntent::of($item, $location, '-'.propertyQty($rng, 20), MovementReason::Waste, 'property')],
            5 => [MovementIntent::of($item, $location, ($rng->getInt(0, 1) === 0 ? '-' : '').propertyQty($rng, 10), MovementReason::CountAdjustment, 'property')],
            6 => [MovementIntent::of($item, $location, '-'.propertyQty($rng, 20), $rng->getInt(0, 1) === 0 ? MovementReason::Sale : MovementReason::ProductionConsume, 'property')],
            7, 8 => [MovementIntent::of($item, $location, propertyQty($rng, 15), MovementReason::Reservation, 'property', null, $ref())],
            9 => [MovementIntent::of($item, $location, '-'.propertyQty($rng, 15), MovementReason::ReservationRelease, 'property', null, $ref())],
            10 => [MovementIntent::of($item, $location, '-'.propertyQty($rng, 15), $rng->getInt(0, 1) === 0 ? MovementReason::Sale : MovementReason::ProductionConsume, 'property', null, $ref())],
            default => (function () use ($rng, $item, $locations): array {
                $from = $rng->getInt(0, 2);
                $to = ($from + $rng->getInt(1, 2)) % 3;

                return MovementIntent::transfer($item, $locations[$from], $locations[$to], propertyQty($rng, 30), 'property');
            })(),
        };
    };

    $ops = $rng->getInt(1, 50);
    for ($op = 1; $op <= $ops; $op++) {
        $intents = $randomIntent();
        if ($rng->getInt(0, 4) === 0) { // a batch of 2 to 4 operations, all-or-nothing
            for ($extra = $rng->getInt(1, 3); $extra > 0; $extra--) {
                $intents = [...$intents, ...$randomIntent()];
            }
        }

        // what the model says the batch does, and whether the Ledger must refuse it
        $next = $model;
        foreach ($intents as $intent) {
            $key = $intent->itemId.':'.$intent->locationId;
            $next[$key] ??= [BigDecimal::zero(), BigDecimal::zero()];
            $qty = $intent->qty->toBigDecimal();
            $reservedOnly = in_array($intent->reason, [MovementReason::Reservation, MovementReason::ReservationRelease], true);
            $both = in_array($intent->reason, [MovementReason::Sale, MovementReason::ProductionConsume], true) && $intent->reservationRef !== null;
            if (! $reservedOnly) {
                $next[$key][0] = $next[$key][0]->plus($qty);
            }
            if ($reservedOnly || $both) {
                $next[$key][1] = $next[$key][1]->plus($qty);
            }
        }
        $refused = false;
        foreach ($next as [$onHand, $reserved]) {
            $refused = $refused || $onHand->isNegative() || $reserved->isNegative();
        }

        $before = DB::table('stock_movements')->count();
        try {
            $ledger->appendMany($intents);
            expect($refused)->toBeFalse("seed {$seed}, op {$op}: the Ledger wrote a batch the model refuses");
            $model = $next;
            $written += count($intents);
        } catch (InventoryError $e) {
            expect($refused)->toBeTrue("seed {$seed}, op {$op}: the Ledger refused a valid batch ({$e->messageKey})")
                ->and(DB::table('stock_movements')->count())->toBe($before, "seed {$seed}, op {$op}: a refused batch left rows behind");
        }
    }

    $oracle = ledgerOracle($branch->id);
    $expected = [];
    foreach ($model as $key => [$onHand, $reserved]) {
        if (! $onHand->isZero() || ! $reserved->isZero()) {
            $expected[$key] = [(string) $onHand->toScale(4), (string) $reserved->toScale(4)];
        }
    }
    ksort($expected);

    expect(DB::table('stock_movements')->where('branch_id', $branch->id)->count())->toBe($written, "seed {$seed}: movement count")
        ->and($oracle['movements'])->toBe($expected, "seed {$seed}: sum(movements) != model")
        ->and($oracle['balances'])->toBe($oracle['movements'], "seed {$seed}: stock_balances != sum(movements)")
        ->and($oracle['view'])->toBe($oracle['movements'], "seed {$seed}: item_stock_view != sum(movements)");

    // and Stock::balanceAt(location, now), the reader Shifts will use, agrees for every location
    $stock = app(Stock::class);
    $now = CarbonImmutable::now();
    foreach ($locations as $location) {
        foreach ($stock->locationAt($location, $now) as $itemId => $level) {
            $key = $itemId.':'.$location;
            $pair = [$level->onHand->toString(), $level->reserved->toString()];
            expect($pair === ['0.0000', '0.0000'] ? null : $pair)->toBe($oracle['balances'][$key] ?? null, "seed {$seed}: balanceAt {$key}");
        }
    }
})->with(ledgerPropertySeeds())->group('gate:p0', 'property', 'module:inventory');
