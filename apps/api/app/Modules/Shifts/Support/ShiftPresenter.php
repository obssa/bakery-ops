<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Identity\Contracts\People;
use App\Modules\Shifts\Counts\CountPresenter;
use App\Modules\Shifts\Counts\CountReads;
use App\Modules\Shifts\Models\HandoverNote;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Shift and ShiftSummary of the API (phase-3 plan section 4, "Resources"). A summary is the same without `roster`, `counts` and
 * `notes_count`. Times are UTC ISO-8601 (the screens show Africa/Addis_Ababa), `date` is the local start date. `counts` are
 * the CountSummary of every revision (step 3.3a) and `notes_count` is the number of handover notes written on the shift (step 3.6). Names come from Identity's People in one query per page. `me_on_roster` is
 * about the person acting (the short-code actor, else the logged-in account): on the roster, not removed. Resolved per use.
 */
final class ShiftPresenter
{
    public function __construct(
        private readonly People $people,
        private readonly ActorContext $actor,
        private readonly CountReads $countReads,
        private readonly CountPresenter $countPresenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function one(ShiftInstance $shift): array
    {
        return $this->build($this->load([$shift]), true)[0];
    }

    /**
     * @param  iterable<ShiftInstance>  $shifts
     * @return list<array<string, mixed>>
     */
    public function many(iterable $shifts): array
    {
        $list = [];
        foreach ($shifts as $shift) {
            $list[] = $shift;
        }

        return $this->build($this->load($list), false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function station(Station $station): array
    {
        return [
            'id' => $station->id,
            'code' => $station->code->value,
            'name_en' => $station->name_en,
            'name_am' => $station->name_am,
            'tracks_shifts' => $station->tracks_shifts,
            'requires_overseer' => $station->requires_overseer,
        ];
    }

    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }

    /**
     * @param  list<ShiftInstance>  $shifts
     * @return list<ShiftInstance>
     */
    private function load(array $shifts): array
    {
        $collection = new Collection($shifts);
        $collection->load(['station', 'template', 'roster']);

        return array_values($collection->all());
    }

    /**
     * @param  list<ShiftInstance>  $shifts
     * @return list<array<string, mixed>>
     */
    private function build(array $shifts, bool $full): array
    {
        $ids = [];
        foreach ($shifts as $shift) {
            foreach ([$shift->started_by_user_id, $shift->opened_by_user_id, $shift->closed_by_user_id, $shift->cancelled_by_user_id] as $id) {
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
            foreach ($shift->roster as $row) {
                $ids[] = $row->user_id;
            }
        }
        $names = $this->people->names($ids);
        $me = $this->actor->effectiveUserId();
        $person = static fn (?int $id): ?array => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? ''];

        $noteCounts = $full && $shifts !== []
            ? HandoverNote::query()->whereIn('shift_instance_id', array_map(static fn (ShiftInstance $s): int => $s->id, $shifts))
                ->selectRaw('shift_instance_id, count(*) as n')->groupBy('shift_instance_id')->pluck('n', 'shift_instance_id')->all()
            : [];

        $out = [];
        foreach ($shifts as $shift) {
            $roster = $shift->roster->sortBy('id')->values();
            $row = [
                'id' => $shift->id,
                'client_uuid' => $shift->client_uuid,
                'station' => self::station($shift->station),
                'template' => $shift->template === null ? null : [
                    'id' => $shift->template->id,
                    'name_en' => $shift->template->name_en,
                    'name_am' => $shift->template->name_am,
                    'start_time' => substr($shift->template->start_time, 0, 5),
                    'end_time' => substr($shift->template->end_time, 0, 5),
                ],
                'date' => $shift->date->toDateString(),
                'status' => $shift->status->value,
                'origin' => $shift->origin->value,
                'opening_mode' => $shift->opening_mode?->value,
                'starts_at' => self::time($shift->starts_at),
                'ends_at' => self::time($shift->ends_at),
                'started_by' => $person($shift->started_by_user_id),
                'started_at' => self::time($shift->started_at),
                'opened_by' => $person($shift->opened_by_user_id),
                'opened_at' => self::time($shift->opened_at),
                'closed_by' => $person($shift->closed_by_user_id),
                'closing_started_at' => self::time($shift->closing_started_at),
                'submitted_at' => self::time($shift->submitted_at),
                'locked_at' => self::time($shift->locked_at),
                'cancelled_at' => self::time($shift->cancelled_at),
                'cancelled_by' => $person($shift->cancelled_by_user_id),
                'cancel_reason' => $shift->cancel_reason,
                'me_on_roster' => $me !== null && $roster->contains(static fn (ShiftRoster $r): bool => $r->user_id === $me),
                'version' => $shift->version,
            ];
            if ($full) {
                $row['roster'] = $roster->map(static fn (ShiftRoster $r): array => [
                    'user' => $person($r->user_id),
                    'source' => $r->source->value,
                    'joined_at' => self::time($r->joined_at),
                    'left_at' => self::time($r->left_at),
                ])->all();
                $row['counts'] = $this->countPresenter->summaries($this->countReads->all($shift));
                $row['notes_count'] = (int) ($noteCounts[$shift->id] ?? 0);
            }
            $out[] = $row;
        }

        return $out;
    }
}
