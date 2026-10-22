<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

/**
 * How a person came to be on a shift's roster (shift_roster.source, CHECK shift_roster_source_check): put on the schedule,
 * the person who started it, a call-in, or someone who joined a running shift.
 */
enum RosterSource: string
{
    case Scheduled = 'scheduled';
    case Started = 'started';
    case CallIn = 'call_in';
    case Joined = 'joined';
}
