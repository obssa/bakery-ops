<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

/**
 * How a shift came to exist (shift_instances.origin, CHECK shift_instances_origin_check): from the schedule, started by a
 * person at a station with no scheduled shift, or opened for a call-in (ADR 0013, rule 7).
 */
enum ShiftOrigin: string
{
    case Scheduled = 'scheduled';
    case Started = 'started';
    case CallIn = 'call_in';
}
