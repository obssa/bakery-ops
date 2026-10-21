<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Models\Unit;
use App\Shared\Values\NumericOverflow;
use App\Shared\Values\Quantity;

/**
 * manifest catalog.provides: "Units::toBase(qty, unit) -> Quantity". The one place a display unit becomes a base unit
 * (traps.md, Units: convert at recipe explosion only, 4 dp half-up through Quantity). toBase is qty x factor and
 * fromBase is qty / factor; with the standard units both are exact, so every quantity round-trips at 4 dp.
 * A unit is given as a model, an id or a code. Resolved per use: the lookup cache lives on the instance only.
 */
final class Units
{
    /** @var array<int|string, Unit> */
    private array $resolved = [];

    public function toBase(Quantity|string|int $qty, Unit|int|string $unit): Quantity
    {
        return Quantity::of($qty)->multipliedBy($this->resolve($unit)->factor);
    }

    public function fromBase(Quantity|string|int $qty, Unit|int|string $unit): Quantity
    {
        return Quantity::of($qty)->dividedBy($this->resolve($unit)->factor);
    }

    /** The base unit $unit converts to (itself when it is a base unit). */
    public function baseOf(Unit|int|string $unit): Unit
    {
        $unit = $this->resolve($unit);
        if ($unit->isBase()) {
            return $unit;
        }

        return $this->resolve((int) $unit->base_unit_id);
    }

    /** Both units measure the same thing (share a base unit). */
    public function convertible(Unit|int|string $a, Unit|int|string $b): bool
    {
        return $this->baseOf($a)->id === $this->baseOf($b)->id;
    }

    /**
     * $qty in $unit, expressed in the base unit of $target (the unit an item is stocked in). Refuses a unit that does
     * not convert, naming the request field it came from.
     *
     * @throws CatalogError catalog.unit.incompatible (422)
     * @throws NumericOverflow when the base quantity does not fit NUMERIC(14,4)
     */
    public function toBaseOf(Quantity|string|int $qty, Unit|int|string $unit, Unit|int|string $target, string $field): Quantity
    {
        if (! $this->convertible($unit, $target)) {
            throw CatalogError::unitIncompatible($field);
        }

        return $this->toBase($qty, $unit);
    }

    public function resolve(Unit|int|string $unit): Unit
    {
        if ($unit instanceof Unit) {
            return $unit;
        }
        $key = is_int($unit) ? $unit : 'code:'.$unit;
        if (! isset($this->resolved[$key])) {
            $query = Unit::query()->withTrashed();
            $this->resolved[$key] = is_int($unit)
                ? $query->findOrFail($unit)
                : $query->where('code', $unit)->firstOrFail();
        }

        return $this->resolved[$key];
    }
}
