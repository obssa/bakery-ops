<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

/** What was decided about a count (stock_counts.decision, CHECK stock_counts_decision_check); set with decided_at (steps 3.3b, 3.3c). */
enum CountDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
