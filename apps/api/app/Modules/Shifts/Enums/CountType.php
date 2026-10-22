<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

/** Which boundary of a shift a count is taken at (stock_counts.type, CHECK stock_counts_type_check). */
enum CountType: string
{
    case Opening = 'opening';
    case Closing = 'closing';
}
