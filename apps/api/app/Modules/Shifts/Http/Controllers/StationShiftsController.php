<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Shifts\Actions\OpenAdHocShift;
use App\Modules\Shifts\Actions\ShiftRows;
use App\Modules\Shifts\Actions\StartShift;
use App\Modules\Shifts\Counts\CountPresenter;
use App\Modules\Shifts\Counts\CountReads;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Http\Requests\OpenAdHocShiftRequest;
use App\Modules\Shifts\Http\Requests\StartShiftRequest;
use App\Modules\Shifts\Models\HandoverNote;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftPresenter;
use App\Shared\Actor\ActorContext;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shifts of one station (phase-3 plan section 4, step 3.2b). A station whose tracks_shifts is false has no shifts: every action
 * on it is 422 shifts.station.not_tracked. {station} binds through the BranchScoped model, so another branch's station is 404.
 *
 *   GET  /stations/{station}/shifts/current         200 {data: Shift or null, server_time}: the running shift (opening, open, counting); polled (ETag, 304)
 *   POST /stations/{station}/shifts/start           201 {shift, counts}: `opening` with its draft opening counts, or `open` with none (carried);
 *                                                   200 for a repeat or for the starter resuming their opening shift
 *   POST /stations/{station}/shifts/open-ad-hoc     201 {shift}; 200 when a shift already runs (the person joins it)
 */
final class StationShiftsController
{
    public function __construct(
        private readonly ShiftPresenter $presenter,
        private readonly ShiftAccess $access,
        private readonly ActorContext $actor,
    ) {}

    public function current(Request $input, Station $station, ShiftRows $rows): Response
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', $station);
        $this->access->assertTracked($station);

        $shift = $rows->running($station->id);
        // the body carries `notes_count` and the count summaries, which the shift's own version does not move (a handover note, a recount
        // after a rejection): count them, so the bar never lags and a row committed late under a lower id still changes the tag
        $notes = $shift === null ? 0 : HandoverNote::query()->where('shift_instance_id', $shift->id)->count();
        $stamp = $shift === null ? null : StockCount::query()->where('shift_instance_id', $shift->id)->toBase()
            ->selectRaw('count(*) as total, coalesce(sum(version), 0) as versions, max(id) as newest')->first();
        $etag = ConditionalPoll::etag('shifts.current', [
            $station->id, $this->actor->effectiveUserId(), $shift?->id, $shift?->version, $shift?->updated_at?->getTimestamp(), $shift?->status->value,
            $notes, (int) ($stamp->total ?? 0), (int) ($stamp->versions ?? 0), (int) ($stamp->newest ?? 0),
        ]);

        return ConditionalPoll::respond($input, $etag, fn (): array => [
            'data' => $shift === null ? null : $this->presenter->one($shift),
            'server_time' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    public function start(StartShiftRequest $input, Station $station, StartShift $start, CountReads $reads, CountPresenter $counts): JsonResponse
    {
        $outcome = $start->handle($station, $input->options());
        $opening = $counts->many($reads->latest($outcome->shift, CountType::Opening));

        return response()->json(['shift' => $this->presenter->one($outcome->shift), 'counts' => $opening], $outcome->created ? 201 : 200);
    }

    public function openAdHoc(OpenAdHocShiftRequest $input, Station $station, OpenAdHocShift $open): JsonResponse
    {
        $outcome = $open->handle($station, $input->clientUuid(), $input->userId());

        return response()->json(['shift' => $this->presenter->one($outcome->shift)], $outcome->created ? 201 : 200);
    }
}
