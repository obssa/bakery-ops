<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

/**
 * Why a production request exists (phase-2 plan section 3, migration 15): `manual` (someone asked on the pad), `schedule` (the
 * planner set a due time) or `stock_out` (the product ran out and the StockOut listener asked). Only a stock_out request holds
 * the open_stock_out_key. The table repeats the list in the CHECK production_requests_reason_check.
 */
enum RequestReason: string
{
    case Schedule = 'schedule';
    case StockOut = 'stock_out';
    case Manual = 'manual';
}
