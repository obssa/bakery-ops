<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Inventory\Contracts\LocationView;
use App\Modules\Inventory\Contracts\StockLevel;

/** A stock place of the station at the replayed moment: its balances (ascending item id) and the last count locked there by then. */
final readonly class ReplayPlace
{
    /**
     * @param  list<StockLevel>  $levels
     */
    public function __construct(public LocationView $location, public array $levels, public ?ReplayLink $lastLocked) {}
}
