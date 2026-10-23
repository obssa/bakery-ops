<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use App\Modules\Orders\Contracts\OpenOrderView;
use App\Modules\Shifts\Models\ShiftInstance;
use Carbon\CarbonImmutable;

/**
 * The state of a station at a point in time (Replay::at): the shift that held the station then, the people on duty, every stock place with its
 * balances and its last locked count, the orders that were open and the counts in play. Plain values and the shift row; the API's JSON is
 * Handover\ReplayPresenter's.
 */
final readonly class ReplayState
{
    /**
     * @param  list<ReplayDuty>  $onDuty
     * @param  list<ReplayPlace>  $places
     * @param  list<OpenOrderView>  $openOrders
     * @param  list<ReplayCount>  $counts
     */
    public function __construct(
        public CarbonImmutable $at,
        public int $stationId,
        public ?ShiftInstance $shift,
        public array $onDuty,
        public array $places,
        public array $openOrders,
        public array $counts,
    ) {}
}
