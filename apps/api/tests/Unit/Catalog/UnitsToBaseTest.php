<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Support\StandardUnits;
use App\Shared\Values\Quantity;

/*
 * Units::toBase is the one place display units become base units (traps.md, Units: convert at recipe explosion only,
 * 4 dp). Plain PHP over in-memory Unit models: the arithmetic must hold without a database. Every seeded unit
 * round-trips: fromBase(toBase(q)) == q at four decimals, and toBase is q x factor exactly.
 */

/** @return array<string, Unit> the standard units as unsaved models, keyed by code */
function standardUnitModels(): array
{
    $models = [];
    $id = 0;
    foreach (StandardUnits::ALL as $code => $spec) {
        $unit = new Unit;
        $unit->forceFill([
            'id' => ++$id,
            'code' => $code,
            'name_en' => $spec['name_en'],
            'name_am' => $spec['name_am'],
            'base_unit_id' => $spec['base'] === null ? null : $models[$spec['base']]->id,
            'factor' => $spec['factor'],
        ]);
        $models[$code] = $unit;
    }

    return $models;
}

$samples = ['0', '1', '0.0001', '0.25', '2.5', '12.3456', '999.9999', '-3.5'];

it('converts to base units as qty x factor at four decimals', function (string $code, string $qty, string $expected) {
    $units = new Units;

    expect($units->toBase($qty, standardUnitModels()[$code])->toString())->toBeDecimal($expected);
})->with([
    '250 g' => ['g', '250', '250.0000'],
    '0.25 kg' => ['kg', '0.25', '250.0000'],
    '1.2345 kg' => ['kg', '1.2345', '1234.5000'],
    '0.5 L' => ['L', '0.5', '500.0000'],
    '330 ml' => ['ml', '330', '330.0000'],
    '2 slices' => ['slice', '2', '2.0000'],
    '3 trays' => ['tray', '3', '36.0000'],
    '7 pcs' => ['pcs', '7', '7.0000'],
])->group('unit', 'module:catalog');

it('round-trips every seeded unit to four decimals', function () use ($samples) {
    $units = new Units;

    foreach (standardUnitModels() as $code => $unit) {
        foreach ($samples as $qty) {
            $base = $units->toBase($qty, $unit);
            $back = $units->fromBase($base, $unit);

            expect($base->toString())->toMatch('/^-?\d+\.\d{4}$/', "{$code}: {$qty}")
                ->and($base->equals(Quantity::of($qty)->multipliedBy($unit->factor)))->toBeTrue("{$code}: {$qty} x factor")
                ->and($back->toString())->toBe(Quantity::of($qty)->toString(), "{$code}: {$qty} round trip");
        }
    }
})->group('unit', 'module:catalog');

it('accepts a Quantity as well as a decimal string and never a float', function () {
    $kg = standardUnitModels()['kg'];
    $units = new Units;

    expect($units->toBase(Quantity::of('1.5'), $kg)->toString())->toBeDecimal('1500.0000')
        ->and(fn () => $units->toBase('1.5.0', $kg))->toThrow(InvalidArgumentException::class);
})->group('unit', 'module:catalog');

it('lists g, ml and pcs as the only base units of the standard set, each with factor 1', function () {
    $bases = array_keys(array_filter(StandardUnits::ALL, fn (array $spec): bool => $spec['base'] === null));

    expect($bases)->toBe(['g', 'ml', 'pcs']);
    foreach ($bases as $code) {
        expect(StandardUnits::ALL[$code]['factor'])->toBe('1.0000')
            ->and(standardUnitModels()[$code]->isBase())->toBeTrue();
    }
    expect(standardUnitModels()['kg']->isBase())->toBeFalse();
})->group('unit', 'module:catalog');
