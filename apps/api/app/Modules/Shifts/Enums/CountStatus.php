<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The count machine (phase-3 plan section 3, migration 9; section 6 item 3, step 3.3a):
 * draft -> awaiting_cosign | pending_approval | approved; awaiting_cosign -> pending_approval | approved | rejected;
 * pending_approval -> approved | rejected; approved -> locked; locked and rejected are final.
 *
 * - draft: being counted; the lines are editable (the lines' freeze triggers allow it only in this status).
 * - awaiting_cosign: submitted at a station that requires an overseer; a second person co-signs (step 3.3c).
 * - pending_approval: submitted with a variance; an admin or manager decides (step 3.3b).
 * - approved: no variance (straight from the submit), or decided; the instant before the lock, which one transaction
 *   crosses once step 3.4 lands. Until then an approved count stays approved, and Locks::previousClosing already treats it as
 *   the carried value.
 * - locked: hash-chained and immutable (step 3.4).
 * - rejected: sent back; a recount is a new row that supersedes it (step 3.3b).
 *
 * stock_counts repeats the values in the CHECK stock_counts_status_check. Every pair not listed in transitions() throws
 * IllegalTransition.
 */
enum CountStatus: string
{
    use StateMachine;

    case Draft = 'draft';
    case AwaitingCosign = 'awaiting_cosign';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Locked = 'locked';
    case Rejected = 'rejected';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::AwaitingCosign, self::PendingApproval, self::Approved],
            self::AwaitingCosign->value => [self::PendingApproval, self::Approved, self::Rejected],
            self::PendingApproval->value => [self::Approved, self::Rejected],
            self::Approved->value => [self::Locked],
            self::Locked->value => [],
            self::Rejected->value => [],
        ];
    }

    /** Rule R-A: at most one unresolved count per place (awaiting_cosign or pending_approval). */
    public function isUnresolved(): bool
    {
        return in_array($this, [self::AwaitingCosign, self::PendingApproval], true);
    }

    /** Has the counter handed it in (and it was not sent back)? */
    public function isSubmitted(): bool
    {
        return in_array($this, [self::AwaitingCosign, self::PendingApproval, self::Approved, self::Locked], true);
    }
}
