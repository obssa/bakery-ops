<?php

declare(strict_types=1);

use App\Modules\Inventory\Costs\Costing;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Property test of the recipe cost arithmetic against an exact rational oracle (phase-2 plan sections 6 and 7), like the
 * moving-average one. For random recipes (seeded; the seed is in the test name and in every failure message, rerun one with
 * RECIPE_COST_SEED=<n>):
 *   1. the sum of the exact line costs equals the oracle's rational sum of qty x (100 + waste) / 100 x rate, with no
 *      rounding on the way;
 *   2. the cost per unit is that exact sum over the yield, rounded ONCE, half-up, to 4 dp (never from rounded lines);
 *   3. the margin amount is the price less that stored cost, and the percentage is the exact quotient rounded once to 2 dp,
 *      half-up (ties away from zero when the margin is negative); no percentage for a price of zero.
 * No PHP float is used anywhere in the oracle.
 */

/** @return array<string, array{0: int}> */
function recipeCostSeeds(): array
{
    $one = getenv('RECIPE_COST_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 20261022 + $i * 7919, range(1, 300));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

/** A non-negative decimal string with $scale places, up to $max units, from integer draws only. */
function recipeCostDraw(Randomizer $rng, int $max, int $scale): string
{
    $units = $rng->getInt(0, 10 ** $scale * $max);

    return $scale === 0 ? (string) $units : sprintf('%d.%0'.$scale.'d', intdiv($units, 10 ** $scale), $units % (10 ** $scale));
}

/** Half-up (ties away from zero) of a rational to $scale decimals, by integer arithmetic only. */
function recipeCostRound(BigRational $x, int $scale): string
{
    $negative = $x->isNegative();
    $abs = $negative ? $x->negated() : $x;
    $n = $abs->getNumerator();
    $d = $abs->getDenominator();
    $factor = BigInteger::of(10)->power($scale);
    $rounded = $n->multipliedBy($factor)->multipliedBy(2)->plus($d)->quotient($d->multipliedBy(2));
    $text = (string) BigDecimal::ofUnscaledValue($rounded, $scale);

    return $negative && ! $rounded->isZero() ? '-'.$text : $text;
}

it('matches the exact rational oracle over random recipes', function (int $seed) {
    $rng = new Randomizer(new Mt19937($seed));
    $lines = $rng->getInt(1, 8);

    $exact = BigDecimal::zero();
    $oracle = BigRational::of('0');
    $detail = [];
    for ($i = 1; $i <= $lines; $i++) {
        $qty = recipeCostDraw($rng, 5000, 4);
        $waste = recipeCostDraw($rng, 99, 2);
        $rate = recipeCostDraw($rng, 100, 4);
        $detail[] = "{$qty} +{$waste}% @ {$rate}";

        $line = Costing::lineCost(Quantity::of($qty), $waste, UnitCost::of($rate));
        $expected = BigRational::of($qty)->multipliedBy(BigRational::of('100')->plus($waste))->dividedBy(100)->multipliedBy($rate);
        expect(BigRational::of((string) $line)->isEqualTo($expected))->toBeTrue("seed {$seed}, line {$i}: {$qty} +{$waste}% @ {$rate} is not exact");

        $exact = $exact->plus($line);
        $oracle = $oracle->plus($expected);
    }
    $message = "seed {$seed}: ".implode('; ', $detail);

    expect(BigRational::of((string) $exact)->isEqualTo($oracle))->toBeTrue($message.' (sum)')
        ->and(Costing::show($exact))->toBe(recipeCostRound($oracle, 4), $message.' (total shown)');

    $yield = recipeCostDraw($rng, 200, 2);
    if (BigDecimal::of($yield)->isZero()) {
        $yield = '0.01';
    }
    $perUnit = Costing::perUnit($exact, Quantity::of($yield));
    $oraclePerUnit = recipeCostRound($oracle->dividedBy($yield), 4);
    expect($perUnit->toString())->toBe($oraclePerUnit, $message." / yield {$yield} (one half-up rounding of the exact quotient)");

    $price = recipeCostDraw($rng, 500, 2);
    $margin = BigRational::of($price)->minus($oraclePerUnit);
    expect(Costing::marginAmount(Money::of($price), $perUnit))->toBe(recipeCostRound($margin, 4), $message." price {$price} (margin)");
    if (BigDecimal::of($price)->isZero()) {
        expect(Costing::marginPct(Money::of($price), $perUnit))->toBeNull($message.' (no percentage of a free price)');
    } else {
        $pct = $margin->dividedBy($price)->multipliedBy(100);
        expect(Costing::marginPct(Money::of($price), $perUnit))->toBe(recipeCostRound($pct, 2), $message." price {$price} (margin percentage)");
    }
})->with(recipeCostSeeds())->group('unit', 'property', 'module:inventory');
