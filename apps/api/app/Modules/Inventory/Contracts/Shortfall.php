<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Values\Quantity;
use InvalidArgumentException;

/**
 * Why a reservation was refused (manifest: Shortfall{ingredient, qty_short, station}): every shortage, in ascending
 * (item_id, location_id) order, with the first one's ingredient, quantity short (base unit) and station on top for the
 * message a pad shows ("2 slices of bread short at the kitchen"). Nothing was reserved.
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
