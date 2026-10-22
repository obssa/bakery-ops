<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Events\ShiftOpened;

/**
 * Builds ShiftOpened from a shift that just reached `open` and dispatches it. Called inside the action's transaction: the event is
 * ShouldDispatchAfterCommit, so it leaves only when the outermost transaction commits and never when it rolls back.
 */
final class ShiftAnnouncer
{
    public function opened(ShiftInstance $shift): void
    {
        $shift->loadMissing(['station', 'template']);
        $station = $shift->station;
        $template = $shift->template;

        event(new ShiftOpened(
            $shift->branch_id,
            $shift->id,
            $station->id,
            $station->name_en,
            $station->name_am,
            $shift->date->toDateString(),
            $shift->origin->value,
            $shift->opening_mode->value ?? 'carried',
            $template?->id,
            $template?->name_en,
            $template?->name_am,
            $shift->starts_at?->toIso8601String(),
            (int) $shift->opened_by_user_id,
        ));
    }
}
