<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Where a line goes and draws its stock from (phase-1 plan section 4, "Where a line's stock is"; ADR 0008 decision 1):
 * the station that makes it (Catalog Routing::stationFor; null for a display-case line, which the cashier picks), the
 * location its stock is reserved at (Inventory Locations::forProduct: the station's location, or the display case),
 * and for a to-order product the version of its active recipe.
 */
final readonly class LineRoute
{
    public function __construct(
        public ?int $stationId,
        public int $locationId,
        public ?int $recipeVersion,
    ) {}

    /** A display-case line: no station step, ready at send. */
    public function isDisplayCase(): bool
    {
        return $this->stationId === null;
    }
}
