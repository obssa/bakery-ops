<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Actions;

use App\Modules\Shifts\Enums\ShiftOpeningMode;
use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;

/**
 * The reads and the one insert every way of making a shift shares (schedule, start from a template or ad hoc, call-in): the
 * running shift of a station (the unique key's row), the next sequence of a slot, and the row itself with the actor stamp of the
 * request (actor_station_id is the station the person works at, `station_id` is the shift's own). Resolved per use.
 */
final class ShiftRows
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
    ) {}

    /** The shift that holds the station (opening, open or counting), if any. */
    public function running(int $stationId): ?ShiftInstance
    {
        return ShiftInstance::query()->where('station_id', $stationId)->where('open_key', 1)->first();
    }

    public function byClientUuid(string $clientUuid): ?ShiftInstance
    {
        return ShiftInstance::query()->where('client_uuid', $clientUuid)->first();
    }

    /** 1 for the first instance of a slot (station, local date, template or none), then one more than the highest so far. */
    public function nextSequence(int $stationId, string $date, int $slotKey): int
    {
        $max = ShiftInstance::query()->where('station_id', $stationId)->whereDate('date', $date)->where('slot_key', $slotKey)->max('sequence');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * Insert a shift that has not been opened by anyone before: `scheduled` (nobody holds the station), or `open` (a start or a
     * call-in: carried, the person who opens it is both starter and opener).
     */
    public function insert(
        Station $station,
        string $clientUuid,
        string $date,
        ?ShiftTemplate $template,
        ?ShiftWindow $window,
        ShiftOrigin $origin,
        ShiftStatus $status,
        ?int $openedBy,
        int $sequence,
    ): ShiftInstance {
        $stamp = $this->actor->stamp();
        $now = CarbonImmutable::now()->startOfSecond();
        $open = $status === ShiftStatus::Open;

        $shift = new ShiftInstance;
        $shift->forceFill([
            'branch_id' => $this->branch->id(),
            'client_uuid' => $clientUuid,
            'station_id' => $station->id,
            'template_id' => $template?->id,
            'slot_key' => $template->id ?? 0,
            'sequence' => $sequence,
            'date' => $date,
            'starts_at' => $window?->startsAt,
            'ends_at' => $window?->endsAt,
            'origin' => $origin,
            'status' => $status,
            'open_key' => $status->openKey(),
            'opening_mode' => $open ? ShiftOpeningMode::Carried : null,
            'started_by_user_id' => $open ? $openedBy : null,
            'started_at' => $open ? $now : null,
            'opened_by_user_id' => $open ? $openedBy : null,
            'opened_at' => $open ? $now : null,
            'created_by_user_id' => $stamp['created_by_user_id'],
            'acting_user_id' => $stamp['acting_user_id'],
            'actor_station_id' => $stamp['station_id'],
            'device_id' => $stamp['device_id'],
            'version' => 1,
        ])->save();

        return $shift;
    }
}
