<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\ShiftLocked;
use App\Shared\Events\ShiftOpened;
use Carbon\CarbonImmutable;

/**
 * Builds ShiftOpened from a shift that just reached `open`, and ShiftLocked from one that just reached `locked`, and dispatches them. Called inside the action's transaction: the event is
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

    /** The shift just locked: the chain link each of its counts (the newest revision of each place and type) became. */
    public function locked(ShiftInstance $shift): void
    {
        $shift->loadMissing('station');
        $station = $shift->station;

        /** @var array<string, StockCount> $latest */
        $latest = [];
        foreach (StockCount::query()->where('shift_instance_id', $shift->id)->whereNotNull('chain_seq')->orderBy('seq')->orderBy('id')->get() as $count) {
            $latest[$count->location_id.':'.$count->type->value] = $count;
        }
        $links = [];
        foreach ($latest as $count) {
            $links[] = ['location_id' => $count->location_id, 'chain_seq' => (int) $count->chain_seq, 'payload_hash' => (string) $count->payload_hash];
        }
        usort($links, static fn (array $a, array $b): int => [$a['location_id'], $a['chain_seq']] <=> [$b['location_id'], $b['chain_seq']]);

        event(new ShiftLocked(
            $shift->branch_id,
            $shift->id,
            $station->id,
            $station->name_en,
            $station->name_am,
            $shift->date->toDateString(),
            ($shift->locked_at ?? CarbonImmutable::now())->toIso8601String(),
            $links,
        ));
    }
}
