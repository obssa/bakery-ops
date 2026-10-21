<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

/**
 * How soon a production request is wanted (phase-2 plan section 3, migration 15). Only a planner (`production.plan`) may ask for
 * anything but `normal`. The production_requests table repeats the list in the CHECK production_requests_priority_check.
 */
enum RequestPriority: string
{
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
