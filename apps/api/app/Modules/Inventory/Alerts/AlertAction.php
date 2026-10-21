<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Alerts;

/** What a stock alert evaluation does to the episodes of one level: open one, close the open one, or nothing. */
enum AlertAction: string
{
    case Open = 'open';
    case Close = 'close';
    case Nothing = 'nothing';
}
