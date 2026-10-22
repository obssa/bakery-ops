<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

/** Why a count row replaces an earlier one (stock_counts.supersede_cause on the new row): a recount after a rejection, or an unlock revision (steps 3.3b, 3.4). */
enum SupersedeCause: string
{
    case Recount = 'recount';
    case Unlock = 'unlock';
}
