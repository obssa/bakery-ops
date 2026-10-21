<?php

declare(strict_types=1);

use App\Modules\Inventory\Costs\MovingAverage;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Property test of the moving average against an exact rational oracle (phase-2 plan sections 6 and 10.3). For random
 * purchase sequences (seeded; the seed is in the test name and in every failure message, rerun one with
 * MOVING_AVERAGE_SEED=<n>):
 *   1. every step equals the exact fraction (old_qty x old_cost + qty_in x unit_cost) / (old_qty + qty_in) of the
 *      STORED (already rounded) inputs, rounded half-up to 4 dp by integer arithmetic: one rounding, no more;
 *   2. the stored average never drifts from the unrounded chain of exact fractions by more than 0.00005 per purchase;
 *   3. a convex combination: the result lies between the old cost and the purchase's cost;
 *   4. unvalued stock (no old cost) or no stock: the result is the purchase's own cost.
 * No PHP float is used anywhere in the oracle.
 */

/** @return array<string, array{0: int}> */
function movingAverageSeeds(): array
{
    $one = getenv('MOVING_AVERAGE_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 20261021 + $i * 104729, range(1, 300));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

/** A positive decimal string with $scale places, up to $max units, from integer draws only. */
function averageDraw(Randomizer $rng, int $max, int $scale): string
{
    $units = $rng->getInt(0, 10 ** $scale * $max);

    return $scale === 0 ? (string) $units : sprintf('%d.%0'.$scale.'d', intdiv($units, 10 ** $scale), $units % (10 ** $scale));
}

/** Half-up to 4 dp of a positive fraction, as a decimal string, by integer arithmetic only. */
function roundHalfUp4(BigRational $x): string
{
    $n = $x->getNumerator();
    $d = $x->getDenominator();
    $ten4 = BigInteger::of(10000);
    $rounded = $n->multipliedBy($ten4)->multipliedBy(2)->plus($d)->quotient($d->multipliedBy(2));

    return (string) BigDecimal::ofUnscaledValue($rounded, 4);
}

it('matches the exact rational oracle over random purchase sequences', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $steps = $rng->getInt(1, 12);

    $qty = BigDecimal::of('0.0000');          // on hand before the next purchase
    $stored = null;                            // the stored, rounded average (null = unvalued)
    $exact = null;                             // the unrounded chain
    $drift = BigRational::of('0');
    $bound = BigRational::of('0');
    $half = BigRational::nd(5, 100000);        // 0.00005

    for ($i = 1; $i <= $steps; $i++) {
        // sometimes the stock arrives without a cost (unvalued), sometimes on top of nothing
        if ($i === 1 && $rng->getInt(0, 3) === 0) {
            $qty = BigDecimal::of(averageDraw($rng, 500, 4));
        }
        $qtyIn = averageDraw($rng, 300, 4);
        if (BigDecimal::of($qtyIn)->isZero()) {
            $qtyIn = '0.0001';
        }
        $costIn = averageDraw($rng, 60, 4);

        $next = MovingAverage::next(Quantity::of((string) $qty), $stored === null ? null : UnitCost::of($stored), Quantity::of($qtyIn), UnitCost::of($costIn));

        $message = "seed {$seed}, step {$i}: old_qty {$qty}, old_cost ".($stored ?? 'null').", qty_in {$qtyIn}, unit_cost_in {$costIn}";
        if ($stored === null || $qty->isZero()) {
            expect($next->toString())->toBe((string) BigDecimal::of($costIn)->toScale(4), $message.' (unvalued or empty: the purchase cost)');
            $exact = BigRational::of($costIn);
        } else {
            $total = BigRational::of((string) $qty)->plus($qtyIn);
            $fraction = BigRational::of((string) $qty)->multipliedBy($stored)->plus(BigRational::of($qtyIn)->multipliedBy($costIn))->dividedBy($total);
            expect($next->toString())->toBe(roundHalfUp4($fraction), $message.' (one half-up rounding of the exact fraction)');

            $exact = BigRational::of((string) $qty)->multipliedBy($exact)->plus(BigRational::of($qtyIn)->multipliedBy($costIn))->dividedBy($total);

            $low = BigDecimal::of($stored)->isLessThan($costIn) ? $stored : $costIn;
            $high = BigDecimal::of($stored)->isGreaterThan($costIn) ? $stored : $costIn;
            expect(BigDecimal::of($next->toString())->isGreaterThanOrEqualTo($low) && BigDecimal::of($next->toString())->isLessThanOrEqualTo($high))
                ->toBeTrue($message.' (between the old cost and the purchase cost)');
        }

        $stored = $next->toString();
        $qty = $qty->plus($qtyIn);
        $bound = $bound->plus($half);
        $drift = BigRational::of($stored)->minus($exact)->simplified();
        if ($drift->isNegative()) {
            $drift = $drift->negated();
        }
        expect($drift->isLessThanOrEqualTo($bound))->toBeTrue("seed {$seed}, step {$i}: drift {$drift->getNumerator()}/{$drift->getDenominator()} exceeds {$i} x 0.00005");
    }
})->with(movingAverageSeeds())->group('unit', 'property', 'module:inventory');
