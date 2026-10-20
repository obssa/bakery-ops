<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;
use InvalidArgumentException;

/**
 * Why a reservation was refused (manifest: Shortfall{ingredient, qty_short, station}): every shortage, grouped by the
 * ref it belongs to in the order the refs were given and, within a ref, in ascending (item_id, location_id) order,
 * with the first one's ingredient, quantity short (base unit) and station on top for the message a pad shows ("2
 * slices of bread short at the kitchen"). Nothing was reserved, for any ref.
 */
final readonly class Shortfall
{
    public int $ingredientItemId;

    public Quantity $qtyShort;

    public ?int $stationId;

    public int $locationId;

    /**
     * @param  list<Shortage>  $shortages  at least one
     */
    public function __construct(public array $shortages)
    {
        if ($shortages === []) {
            throw new InvalidArgumentException('A shortfall names at least one shortage.');
        }
        $first = $shortages[0];
        $this->ingredientItemId = $first->itemId;
        $this->qtyShort = $first->qtyShort;
        $this->stationId = $first->stationId;
        $this->locationId = $first->locationId;
    }

    /**
     * The refs that are short, in the order they were given: for Orders, the lines to show the shortfall on.
     *
     * @return list<string>
     */
    public function refs(): array
    {
        $refs = [];
        foreach ($this->shortages as $shortage) {
            if ($shortage->ref !== null && ! in_array($shortage->ref, $refs, true)) {
                $refs[] = $shortage->ref;
            }
        }

        return $refs;
    }

    /**
     * @return list<Shortage> the shortages of one ref (empty when that ref could be covered)
     */
    public function for(string $ref): array
    {
        return array_values(array_filter($this->shortages, static fn (Shortage $s): bool => $s->ref === $ref));
    }

    /**
     * @return array{ingredient_item_id: int, qty_short: string, station_id: int|null, location_id: int, shortages: list<array<string, int|string|null>>}
     */
    public function toArray(): array
    {
        return [
            'ingredient_item_id' => $this->ingredientItemId,
            'qty_short' => $this->qtyShort->toString(),
            'station_id' => $this->stationId,
            'location_id' => $this->locationId,
            'shortages' => array_map(static fn (Shortage $s): array => $s->toArray(), $this->shortages),
        ];
    }
}
