<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The print job machine (step 5.3a, phase-5 plan 4.3; ADR 0019): queued -> claimed (a bridge took it) -> printed; a claimed job goes back
 * to queued when the bridge reports a failure or says nothing for a minute (print:sweep), and becomes failed instead after its third claim;
 * a job nobody is printing is expired after 15 minutes, queued or with a stale claim. printed, failed and expired are final. The table
 * enforces the same list with the CHECK print_jobs_status_check.
 */
enum PrintJobStatus: string
{
    use StateMachine;

    case Queued = 'queued';
    case Claimed = 'claimed';
    case Printed = 'printed';
    case Failed = 'failed';
    case Expired = 'expired';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Queued->value => [self::Claimed, self::Expired],
            self::Claimed->value => [self::Printed, self::Queued, self::Failed, self::Expired],
            self::Printed->value => [],
            self::Failed->value => [],
            self::Expired->value => [],
        ];
    }
}
