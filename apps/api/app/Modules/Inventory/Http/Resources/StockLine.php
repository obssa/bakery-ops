<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Contracts\StockLevel;

/**
 * One balance at a location together with what the screen needs to show it: the item's names and base unit code.
 */
final readonly class StockLine
{
    public function __construct(
        public StockLevel $level,
        public string $nameEn,
        public string $nameAm,
        public string $baseUnitCode,
    ) {}
}
