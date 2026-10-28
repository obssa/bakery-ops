<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Build;

use App\Modules\Catalog\Contracts\ItemSummary;
use App\Modules\Catalog\Contracts\RecipeView;
use App\Modules\Inventory\Contracts\ClosingBalanceFact;
use App\Modules\Inventory\Contracts\MovementFact;
use App\Modules\Inventory\Contracts\StockOutFact;
use App\Modules\Inventory\Contracts\UnitCostFact;
use App\Modules\Orders\Contracts\LineFact;
use App\Modules\Orders\Contracts\RefundLineFact;
use App\Modules\Orders\Contracts\SaleFact;
use App\Modules\Orders\Contracts\StationLineFact;
use App\Modules\Orders\Contracts\VoidFact;
use App\Modules\Payments\Contracts\PaymentFact;
use App\Modules\Payments\Contracts\RefundFact;
use App\Modules\Production\Contracts\BatchFact;
use App\Modules\Shifts\Contracts\ShiftFact;

/**
 * Everything one local day of one branch is built from, read through the providers' Facts ports and Catalog's contracts in one
 * transaction (FactsReader). Plain values: the builders take these lists and never touch the database.
 */
final readonly class DayFacts
{
    /**
     * @param  list<SaleFact>  $sales
     * @param  list<LineFact>  $lines
     * @param  list<VoidFact>  $voids
     * @param  list<StationLineFact>  $stationLines
     * @param  list<PaymentFact>  $payments
     * @param  list<RefundFact>  $refunds
     * @param  list<BatchFact>  $batches
     * @param  list<MovementFact>  $movements
     * @param  list<ClosingBalanceFact>  $closings
     * @param  list<StockOutFact>  $stockOuts
     * @param  list<UnitCostFact>  $unitCosts
     * @param  list<ShiftFact>  $shifts
     * @param  array<int, ItemSummary>  $items
     * @param  array<string, RecipeView|null>  $recipes  "product id:version"
     * @param  list<RefundLineFact>  $refundLines  the lines refunded that day (step 4.3d)
     */
    public function __construct(
        public string $day,
        public string $timezone,
        public array $sales,
        public array $lines,
        public array $voids,
        public array $stationLines,
        public array $payments,
        public array $refunds,
        public array $batches,
        public array $movements,
        public array $closings,
        public array $stockOuts,
        public array $unitCosts,
        public array $shifts,
        public array $items,
        public array $recipes,
        public array $refundLines = [],
    ) {}
}
