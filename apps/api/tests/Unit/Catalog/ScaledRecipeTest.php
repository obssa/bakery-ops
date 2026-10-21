<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\RecipeLineView;
use App\Modules\Catalog\Contracts\RecipeStepView;
use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Catalog\Contracts\ScaledRecipe;
use App\Shared\I18n\Bilingual;
use App\Shared\Values\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/*
 * Step 2.1 (F2): ScaledRecipe is the arithmetic behind Recipes::scaled(), plain PHP over a RecipeView so it must hold
 * without a database. One line of a recipe for a target is  qty x (1 + waste / 100) x target / yield,  computed in exact
 * decimals and rounded half-up ONCE to four places in the ingredient's base unit; optional lines are not on the card.
 */

/** @return RecipeLineView one line in base units: ingredient id, qty, unit code, optional, waste percentage */
function scaledLine(int $ingredient, string $qty, string $unit = 'g', bool $optional = false, string $waste = '0.00'): RecipeLineView
{
    return new RecipeLineView($ingredient, Quantity::of($qty), $unit, $optional, $waste);
}

/** @param list<RecipeLineView> $lines */
function viewOf(string $yield, array $lines, string $yieldUnit = 'pcs'): RecipeView
{
    return new RecipeView(7, 70, 3, Quantity::of($yield), $yieldUnit, $lines);
}

/** @return array<int, string> ingredient id => quantity */
function quantitiesOf(ScaledRecipe $scaled): array
{
    $out = [];
    foreach ($scaled->lines as $line) {
        $out[$line->ingredientItemId] = $line->qty->toString();
    }

    return $out;
}

it('multiplies every line by target / yield: 40 scaled to 60 is x1.5', function () {
    $scaled = ScaledRecipe::from(viewOf('40', [scaledLine(1, '2500'), scaledLine(2, '750', 'ml'), scaledLine(3, '7', 'pcs')]), '60');

    expect(quantitiesOf($scaled))->toBe([1 => '3750.0000', 2 => '1125.0000', 3 => '10.5000'])
        ->and($scaled->lines[1]->unit)->toBe('ml')
        ->and($scaled->target->toString())->toBe('60.0000')
        ->and($scaled->yield->toString())->toBe('40.0000');
})->group('unit', 'module:catalog');

it('rounds once: 12 scaled to 5 gives 416.6667 of 1000 g, not 416.7 from a factor rounded first', function () {
    $scaled = ScaledRecipe::from(viewOf('12', [scaledLine(1, '1'), scaledLine(2, '1000'), scaledLine(3, '3')]), '5');

    expect(quantitiesOf($scaled))->toBe([1 => '0.4167', 2 => '416.6667', 3 => '1.2500']);
})->group('unit', 'module:catalog');

it('rounds half-up at the fifth decimal, both ways', function (string $qty, string $yield, string $target, string $expected) {
    $scaled = ScaledRecipe::from(viewOf($yield, [scaledLine(1, $qty)]), $target);

    expect(quantitiesOf($scaled)[1])->toBe($expected);
})->with([
    'exactly half rounds up' => ['0.0001', '2', '1', '0.0001'], // 0.00005 -> 0.0001
    'just below half rounds down' => ['0.0001', '2.0001', '1', '0.0000'],
    'a third rounds down' => ['1', '3', '1', '0.3333'],
    'two thirds' => ['2', '3', '1', '0.6667'],
])->group('unit', 'module:catalog');

it('applies waste once, to the scaled quantity: 1 kg at 5 % for twice the yield needs 2.1 kg', function () {
    $scaled = ScaledRecipe::from(viewOf('10', [scaledLine(1, '1000', 'g', false, '5.00'), scaledLine(2, '1', 'g', false, '5.00')]), '20');

    expect(quantitiesOf($scaled))->toBe([1 => '2100.0000', 2 => '2.1000'])
        // and combined with a fraction: 1 g x 1.05 x 5 / 12 = 0.4375
        ->and(quantitiesOf(ScaledRecipe::from(viewOf('12', [scaledLine(1, '1', 'g', false, '5.00')]), '5')))->toBe([1 => '0.4375']);
})->group('unit', 'module:catalog');

it('leaves optional lines out and keeps the order of the rest', function () {
    $scaled = ScaledRecipe::from(viewOf('10', [scaledLine(5, '10'), scaledLine(4, '20', 'g', true), scaledLine(3, '30'), scaledLine(2, '40', 'g', true)]), '10');

    expect(array_map(fn ($l) => $l->ingredientItemId, $scaled->lines))->toBe([5, 3]);
})->group('unit', 'module:catalog');

it('carries the version, the recipe id, the yield unit and the steps as given', function () {
    $steps = [new RecipeStepView(1, Bilingual::of('Mix.', 'ቀላቅሉ።')), new RecipeStepView(2, Bilingual::of('Bake.', 'ይጋግሩ።'))];

    $scaled = ScaledRecipe::from(viewOf('12', [scaledLine(1, '100')], 'pcs'), Quantity::of('24'), $steps);

    expect($scaled->productItemId)->toBe(7)
        ->and($scaled->recipeId)->toBe(70)
        ->and($scaled->version)->toBe(3)
        ->and($scaled->yieldUnit)->toBe('pcs')
        ->and($scaled->steps)->toBe($steps)
        ->and($scaled->toArray()['steps'])->toBe([
            ['order' => 1, 'text_en' => 'Mix.', 'text_am' => 'ቀላቅሉ።'],
            ['order' => 2, 'text_en' => 'Bake.', 'text_am' => 'ይጋግሩ።'],
        ])
        ->and($scaled->toArray()['lines'][0])->toBe(['ingredient_item_id' => 1, 'qty' => '200.0000', 'unit' => 'g'])
        ->and($scaled->toArray())->toMatchArray(['product_item_id' => 7, 'recipe_id' => 70, 'version' => 3, 'yield' => '12.0000', 'yield_unit' => 'pcs', 'target' => '24.0000']);
})->group('unit', 'module:catalog');

it('refuses a target that is not positive and a recipe that yields nothing', function () {
    $view = viewOf('12', [scaledLine(1, '100')]);

    expect(fn () => ScaledRecipe::from($view, '0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScaledRecipe::from($view, '-3'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScaledRecipe::from(viewOf('0', [scaledLine(1, '100')]), '5'))->toThrow(InvalidArgumentException::class);
})->group('unit', 'module:catalog');

it('matches an independent exact computation for random quantities, yields, targets and waste (seeded)', function () {
    $seed = 20261021;
    mt_srand($seed);
    for ($i = 0; $i < 300; $i++) {
        $qty = (string) BigDecimal::of((string) mt_rand(1, 9_999_999))->dividedBy(mt_rand(1, 1000), 4, RoundingMode::HalfUp);
        if (BigDecimal::of($qty)->isZero()) {
            continue;
        }
        $yield = (string) mt_rand(1, 500);
        $target = (string) BigDecimal::of((string) mt_rand(1, 99_999))->dividedBy(mt_rand(1, 100), 4, RoundingMode::HalfUp);
        if (BigDecimal::of($target)->isZero()) {
            continue;
        }
        $waste = number_format(mt_rand(0, 3000) / 100, 2, '.', '');

        $scaled = ScaledRecipe::from(viewOf($yield, [scaledLine(1, $qty, 'g', false, $waste)]), $target);

        // exact: qty x (100 + waste) x target / (100 x yield), rounded half-up once
        $exact = BigDecimal::of($qty)->multipliedBy(BigDecimal::of('100')->plus($waste))->multipliedBy($target)
            ->dividedBy(BigDecimal::of('100')->multipliedBy($yield), 4, RoundingMode::HalfUp);
        expect($scaled->lines[0]->qty->toString())->toBe((string) $exact, "seed {$seed}, iteration {$i}: qty {$qty}, waste {$waste}, yield {$yield}, target {$target}");
    }
})->group('unit', 'module:catalog');
