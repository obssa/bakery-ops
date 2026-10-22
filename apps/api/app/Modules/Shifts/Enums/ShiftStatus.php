<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The shift machine (phase-3 plan section 3, migration 5; ADR 0013):
 * scheduled -> opening | open | cancelled; opening -> open | cancelled; open -> counting; counting -> pending_approval | locked;
 * pending_approval -> counting | locked; locked and cancelled are final.
 *
 * - scheduled: a template on a date, or a call-in announced, that nobody has started. It holds no station.
 * - opening: a person is counting in (the opening count is a draft). The station is held (open_key 1).
 * - open: the shift runs and the ledger accepts movements covered by it (open_key 1).
 * - counting: the outgoing person pressed Close; the station is frozen and the closing counts are being made (open_key 1).
 * - pending_approval: every closing count is submitted and a difference waits for an admin or a manager; the station is free
 *   for the next shift (open_key null). A rejected count sends it back to counting.
 * - locked: every count is approved and locked. Final.
 * - cancelled: a scheduled or opening shift nobody will work. Final.
 *
 * The shift_instances table repeats the values in the CHECK shift_instances_status_check, and open_key and opened_at in
 * step with them (isRunning / openKey / hasOpened). Every pair not listed in transitions() throws IllegalTransition.
 */
enum ShiftStatus: string
{
    use StateMachine;

    case Scheduled = 'scheduled';
    case Opening = 'opening';
    case Open = 'open';
    case Counting = 'counting';
    case PendingApproval = 'pending_approval';
    case Locked = 'locked';
    case Cancelled = 'cancelled';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Scheduled->value => [self::Opening, self::Open, self::Cancelled],
            self::Opening->value => [self::Open, self::Cancelled],
            self::Open->value => [self::Counting],
            self::Counting->value => [self::PendingApproval, self::Locked],
            self::PendingApproval->value => [self::Counting, self::Locked],
            self::Locked->value => [],
            self::Cancelled->value => [],
        ];
    }

    /** Whether the shift holds its station: at most one per station at a time (unique key station_id + open_key). */
    public function isRunning(): bool
    {
        return in_array($this, [self::Opening, self::Open, self::Counting], true);
    }

    /** The shift_instances.open_key of this status: 1 while running, null otherwise. */
    public function openKey(): ?int
    {
        return $this->isRunning() ? 1 : null;
    }

    /** Whether opened_at is set (CHECK shift_instances_opened_at_check). */
    public function hasOpened(): bool
    {
        return in_array($this, [self::Open, self::Counting, self::PendingApproval, self::Locked], true);
    }
}
