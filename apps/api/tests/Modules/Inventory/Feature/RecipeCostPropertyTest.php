<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Contracts\RecipeCost;
use App\Modules\Inventory\Models\ItemCost;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * The whole roll-up against an exact rational oracle: random recipes with ingredients (some without a cost), optional
 * lines, waste, a sub-recipe used by the product, stored in the database and read through Contracts\RecipeCost. The
 * oracle is written separately from the code under test (rationals, no shared helper). Seeded: the seed is in the test name
 * and in every failure message; rerun one with RECIPE_COST_DB_SEED=<n>.
 */

/** @return array<string, array{0: int}> */
function recipeCostDbSeeds(): array
{
    $one = getenv('RECIPE_COST_DB_SEED');
    $seeds = is_string($one) && ctype_digit($one) ? [(int) $one] : array_map(fn (int $i): int => 20261023 + $i * 6007, range(1, 25));

    return array_combine(array_map(fn (int $s): string => "seed {$s}", $seeds), array_map(fn (int $s): array => [$s], $seeds));
}

function recipeCostDbDraw(Randomizer $rng, int $max, int $scale): string
{
    $units = $rng->getInt(0, 10 ** $scale * $max);

    return $scale === 0 ? (string) $units : sprintf('%d.%0'.$scale.'d', intdiv($units, 10 ** $scale), $units % (10 ** $scale));
}

function recipeCostDbRound(BigRational $x, int $scale): string
{
    $negative = $x->isNegative();
    $abs = $negative ? $x->negated() : $x;
    $factor = BigInteger::of(10)->power($scale);
    $rounded = $abs->getNumerator()->multipliedBy($factor)->multipliedBy(2)->plus($abs->getDenominator())->quotient($abs->getDenominator()->multipliedBy(2));
    $text = (string) BigDecimal::ofUnscaledValue($rounded, $scale);

    return $negative && ! $rounded->isZero() ? '-'.$text : $text;
}

it('equals the exact rational roll-up for random recipes with a sub-recipe', function (int $seed) {
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($branch, 'admin')->id);
    $b = $branch->id;
    $rng = new Randomizer(new Mt19937($seed));

    // ingredients in grams, each with a cost or (one time in five) none
    $costs = [];
    $ingredients = [];
    foreach (range(1, $rng->getInt(3, 6)) as $i) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
        $ingredients[] = $item;
        if ($rng->getInt(0, 4) !== 0) {
            $costs[$item->id] = recipeCostDbDraw($rng, 3, 4);
            ItemCost::factory()->create(['branch_id' => $b, 'item_id' => $item->id, 'moving_avg_cost' => $costs[$item->id]]);
        }
    }

    // $make stores one recipe and returns [item, yield, rows]
    $make = function (array $pool, ?Item $sub) use ($rng, $b) {
        $product = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);
        $yield = (string) $rng->getInt(1, 20);
        $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $product->id, 'yield_qty' => $yield, 'yield_unit_id' => catalogUnit('pcs')->id]);
        $rows = [];
        $picked = $pool;
        $picked = $rng->shuffleArray($picked);
        foreach (array_slice($picked, 0, $rng->getInt(1, count($pool))) as $ingredient) {
            $rows[] = ['item' => $ingredient->id, 'qty' => recipeCostDbDraw($rng, 2000, 4), 'waste' => recipeCostDbDraw($rng, 30, 2), 'optional' => $rng->getInt(0, 4) === 0, 'unit' => 'g'];
        }
        if ($sub !== null) {
            $rows[] = ['item' => $sub->id, 'qty' => (string) $rng->getInt(1, 5), 'waste' => '0.00', 'optional' => false, 'unit' => 'pcs'];
        }
        foreach ($rows as &$row) {
            if (BigDecimal::of($row['qty'])->isZero()) {
                $row['qty'] = '1';
            }
            RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $row['item'], 'qty' => $row['qty'], 'unit_id' => catalogUnit($row['unit'])->id, 'waste_pct' => $row['waste'], 'is_optional' => $row['optional']]);
        }
        unset($row);

        return [$product, $yield, $rows];
    };

    // the oracle: [complete, total (rational), missing ids] of one recipe; a sub-recipe is worth its rounded unit cost
    $oracle = function (array $rows, array $subCosts) use ($costs): array {
        $total = BigRational::of('0');
        $missing = [];
        foreach ($rows as $row) {
            if ($row['optional']) {
                continue;
            }
            $rate = $costs[$row['item']] ?? $subCosts[$row['item']] ?? null;
            if ($rate === null) {
                $missing[] = $row['item'];

                continue;
            }
            $total = $total->plus(BigRational::of($row['qty'])->multipliedBy(BigRational::of('100')->plus($row['waste']))->dividedBy(100)->multipliedBy($rate));
        }
        sort($missing);

        return [$missing === [], $total, array_values(array_unique($missing))];
    };

    [$sub, $subYield, $subRows] = $make($ingredients, null);
    [$subComplete, $subTotal, $subMissing] = $oracle($subRows, []);
    $subCosts = $subComplete ? [$sub->id => recipeCostDbRound($subTotal->dividedBy($subYield), 4)] : [];

    [$root, $rootYield, $rootRows] = $make($ingredients, $sub);
    [$complete, $total, $missing] = $oracle($rootRows, $subCosts);
    if (! $subComplete) {
        // the sub-recipe itself is not listed, the items that make it incomplete are
        $missing = array_values(array_unique(array_merge(array_diff($missing, [$sub->id]), $subMissing)));
        sort($missing);
        $complete = false;
    }
    $price = recipeCostDbDraw($rng, 300, 2);
    if (BigDecimal::of($price)->isPositive()) {
        ItemPrice::factory()->create(['branch_id' => $b, 'item_id' => $root->id, 'price' => $price]);
    }

    $cost = app(RecipeCost::class)->rollUp($root);

    $message = "seed {$seed}";
    expect($cost->complete)->toBe($complete, $message)
        ->and($cost->missingCostItemIds)->toBe($missing, $message);
    if (! $complete) {
        expect($cost->totalCost)->toBeNull()->and($cost->costPerUnit)->toBeNull()->and($cost->marginAmount)->toBeNull()->and($cost->marginPct)->toBeNull();

        return;
    }
    $perUnit = recipeCostDbRound($total->dividedBy($rootYield), 4);
    expect($cost->totalCost)->toBe(recipeCostDbRound($total, 4), "{$message} (total)")
        ->and($cost->costPerUnit->toString())->toBe($perUnit, "{$message} (per unit)");
    if (BigDecimal::of($price)->isPositive()) {
        $margin = BigRational::of($price)->minus($perUnit);
        expect($cost->marginAmount)->toBe(recipeCostDbRound($margin, 4), "{$message} (margin)")
            ->and($cost->marginPct)->toBe(recipeCostDbRound($margin->dividedBy($price)->multipliedBy(100), 2), "{$message} (margin pct)");
    } else {
        expect($cost->price)->toBeNull()->and($cost->marginAmount)->toBeNull()->and($cost->marginPct)->toBeNull();
    }
})->with(recipeCostDbSeeds())->group('module:inventory', 'property');
