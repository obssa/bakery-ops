<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use Carbon\CarbonImmutable;

/**
 * A shift that waits for approval is `locked` once the newest revision of every count it has, opening and closing, is approved (or
 * already locked): its last count has resolved. Called inside the transaction of the action that resolved a count (ApproveCount, and
 * CosignCount when the co-signature itself approves a count without a variance), with the shift row already locked.
 */
final class ShiftResolution
{
    public static function lockWhenResolved(ShiftInstance $shift, CarbonImmutable $now): void
    {
        if ($shift->status !== ShiftStatus::PendingApproval) {
            return;
        }
        /** @var array<string, StockCount> $latest */
        $latest = [];
        foreach (StockCount::query()->where('shift_instance_id', $shift->id)->orderBy('seq')->orderBy('id')->get() as $row) {
            $latest[$row->location_id.':'.$row->type->value] = $row;
        }
        foreach ($latest as $row) {
            if (! in_array($row->status, [CountStatus::Approved, CountStatus::Locked], true)) {
                return;
            }
        }

        $to = $shift->status->transitionTo(ShiftStatus::Locked);
        $shift->forceFill([
            'status' => $to,
            'open_key' => $to->openKey(),
            'locked_at' => $now,
            'version' => $shift->version + 1,
        ])->save();
    }
}
