<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Shifts\Enums\RosterSource;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;

/**
 * The writes on shift_roster, called inside an action's transaction (ADR 0013 rule 4). One row per person and shift: a person who
 * joins, leaves and joins again keeps their row (joined_at stays, left_at is cleared), a scheduled person who joins keeps the
 * `scheduled` source and gains joined_at, a person removed from a scheduled shift and put back gets the same row restored. Rows are
 * soft-deleted only while the shift has not started (replaceScheduled); once it has, only left_at moves. Resolved per use: it
 * carries the request's actor stamp.
 */
final class ShiftRosters
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /** Is the person on duty on the shift: joined and not left, row not removed? */
    public function onDuty(ShiftInstance $shift, int $userId): bool
    {
        $row = ShiftRoster::query()->where('shift_instance_id', $shift->id)->where('user_id', $userId)->first();

        return $row instanceof ShiftRoster && $row->isOnDuty();
    }

    /**
     * Put the person on duty. $source is the source of a row made now (a person already on the roster keeps theirs).
     *
     * @return bool whether anything was written (false: the person was on duty already)
     */
    public function join(ShiftInstance $shift, int $userId, RosterSource $source): bool
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $row = ShiftRoster::query()->withTrashed()->where('shift_instance_id', $shift->id)->where('user_id', $userId)->first();
        if (! $row instanceof ShiftRoster) {
            $this->make($shift, $userId, $source, $now);

            return true;
        }
        if (! $row->trashed() && $row->isOnDuty()) {
            return false;
        }
        if ($row->trashed()) {
            $row->restore();
            $row->forceFill(['source' => $source, 'joined_at' => $now, 'left_at' => null])->save();
        } elseif ($row->joined_at === null) {
            $row->forceFill(['joined_at' => $now])->save();
        } else {
            $row->forceFill(['left_at' => null])->save();
        }

        return true;
    }

    /**
     * Take the person off duty.
     *
     * @return bool whether anything was written (false: they were not on duty)
     */
    public function leave(ShiftInstance $shift, int $userId): bool
    {
        $row = ShiftRoster::query()->where('shift_instance_id', $shift->id)->where('user_id', $userId)->first();
        if (! $row instanceof ShiftRoster || ! $row->isOnDuty()) {
            return false;
        }
        $now = CarbonImmutable::now()->startOfSecond();
        $row->forceFill(['left_at' => $row->joined_at !== null && $now < $row->joined_at ? $row->joined_at : $now])->save();

        return true;
    }

    /**
     * Make $userIds the whole roster of a scheduled shift: rows that go are soft-deleted, rows that come back are restored, new
     * people get a `scheduled` row nobody has joined.
     *
     * @param  list<int>  $userIds
     * @return bool whether anything changed
     */
    public function replaceScheduled(ShiftInstance $shift, array $userIds): bool
    {
        $wanted = array_values(array_unique($userIds));
        $changed = false;
        $rows = ShiftRoster::query()->withTrashed()->where('shift_instance_id', $shift->id)->get()->keyBy('user_id');

        foreach ($rows as $userId => $row) {
            if (! $row->trashed() && ! in_array((int) $userId, $wanted, true)) {
                $row->delete();
                $changed = true;
            }
        }
        foreach ($wanted as $userId) {
            $row = $rows->get($userId);
            if ($row instanceof ShiftRoster) {
                if ($row->trashed()) {
                    $row->restore();
                    $changed = true;
                }

                continue;
            }
            $this->make($shift, $userId, RosterSource::Scheduled, null);
            $changed = true;
        }

        return $changed;
    }

    /** The shift moved on: the version is the live feed's merge key (ADR 0009), and updated_at moves with it. */
    public function touch(ShiftInstance $shift): void
    {
        $shift->forceFill(['version' => $shift->version + 1])->save();
    }

    private function make(ShiftInstance $shift, int $userId, RosterSource $source, ?CarbonImmutable $joinedAt): void
    {
        $stamp = $this->actor->stamp();
        $row = new ShiftRoster;
        $row->forceFill([
            'branch_id' => $this->branch->id(),
            'shift_instance_id' => $shift->id,
            'user_id' => $userId,
            'source' => $source,
            'joined_at' => $joinedAt,
            'left_at' => null,
            'created_by_user_id' => $stamp['created_by_user_id'],
            'acting_user_id' => $stamp['acting_user_id'],
            'device_id' => $stamp['device_id'],
        ])->save();
    }
}
