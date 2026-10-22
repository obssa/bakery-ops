<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

/**
 * How a shift's stock was taken over (shift_instances.opening_mode, CHECK shift_instances_opening_mode_check): counted by
 * the incoming person, or carried from the previous locked closing without a count. Null until the shift opens.
 */
enum ShiftOpeningMode: string
{
    case Counted = 'counted';
    case Carried = 'carried';
}
