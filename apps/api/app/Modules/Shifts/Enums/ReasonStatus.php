<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * What became of the reason a line gave for its variance (stock_count_lines.reason_status, CHECK stock_count_lines_reason_status_check):
 * none -> pending (a submit found a variance); pending -> accepted | rejected (the decision, step 3.3b); accepted and rejected are final.
 * A line with no variance stays `none`. It is a machine like every *Status enum (StateMachineExhaustiveTest proves the table).
 */
enum ReasonStatus: string
{
    use StateMachine;

    case None = 'none';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::None->value => [self::Pending],
            self::Pending->value => [self::Accepted, self::Rejected],
            self::Accepted->value => [],
            self::Rejected->value => [],
        ];
    }
}
