<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Identity\Contracts\People;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\LocationView;
use App\Modules\Shifts\Actions\CancelShift;
use App\Modules\Shifts\Actions\CloseShift;
use App\Modules\Shifts\Actions\JoinShift;
use App\Modules\Shifts\Actions\LeaveShift;
use App\Modules\Shifts\Actions\ReplaceRoster;
use App\Modules\Shifts\Actions\ScheduleShift;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Counts\CountPresenter;
use App\Modules\Shifts\Counts\CountReads;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Http\Requests\CancelShiftRequest;
use App\Modules\Shifts\Http\Requests\ListShiftsRequest;
use App\Modules\Shifts\Http\Requests\ReplaceRosterRequest;
use App\Modules\Shifts\Http\Requests\ScheduleShiftRequest;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Modules\Shifts\Support\ShiftPresenter;
use App\Modules\Shifts\Support\ShiftSummaryReader;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shifts (phase-3 plan section 4, step 3.2b). Every write is an AuditedAction in the action classes behind an Idempotency-Key;
 * another branch's shift is 404 through the BranchScoped route binding (rule 8). The route parameter is {shift}.
 *
 *   GET  /shifts                      200 {data: [ShiftSummary], meta} newest first, at the stations the caller works at (stations.all:
 *                                     every station); ?station_id=, ?status=, ?running=1 (polled: ETag, 304), ?from=, ?to=, ?mine=1, ?page=, ?per_page=
 *   GET  /shifts/{shift}              200 the Shift; 403 shifts.station.forbidden for a station the caller does not work at
 *   POST /shifts                      201 the scheduled Shift (shifts.manage); 200 for a client_uuid already used
 *   PUT  /shifts/{shift}/roster       200 the Shift with its new roster (shifts.manage); 409 shifts.shift.not_scheduled
 *   POST /shifts/{shift}/cancel       200 the cancelled Shift (the admin, or the starter of an opening shift); a repeat answers itself
 *   POST /shifts/{shift}/join         200 the Shift with the caller on duty; 409 shifts.shift.not_running
 *   POST /shifts/{shift}/leave        200 the Shift with the caller off duty; 409 shifts.shift.not_running
 *   GET  /shifts/{shift}/cash         200 {opening_total, carried_float_total, cash_sales_total, expected_total, by_channel, currency}: the till's cash so far (step 3.5)
 *   POST /shifts/{shift}/close        200 {shift, counts}: open -> counting, one draft closing count per stock place; a repeat answers it
 *   GET  /shifts/{shift}/summary      200 {movements, cash, open_work}: what the shift did and what is still open
 */
final class ShiftsController
{
    public function __construct(
        private readonly ShiftPresenter $presenter,
        private readonly ShiftAccess $access,
        private readonly People $people,
    ) {}

    public function index(ListShiftsRequest $input): Response
    {
        $person = $this->access->person('shifts.view');
        $stationIds = $this->people->workStationIds($person);
        $query = ShiftInstance::query()->whereIn('station_id', $stationIds);
        if ($input->stationId() !== null) {
            $query->where('station_id', $input->stationId());
        }
        if ($input->status() !== null) {
            $query->where('status', $input->status()->value);
        }
        if ($input->running()) {
            $query->where('open_key', 1);
        }
        if ($input->from() !== null) {
            $query->whereDate('date', '>=', $input->from());
        }
        if ($input->to() !== null) {
            $query->whereDate('date', '<=', $input->to());
        }
        if ($input->mine()) {
            $query->whereHas('roster', fn (Builder $roster) => $roster->where('user_id', $person));
        }

        $page = fn (): JsonResponse => $this->page($input, $query);
        if (! $input->running()) {
            return $page();
        }

        // the polled feed: a few cheap numbers say whether anything changed (ADR 0009)
        $stamp = (clone $query)->toBase()->selectRaw('count(*) as shifts, max(updated_at) as moved, coalesce(sum(version), 0) as versions')->first();
        $etag = ConditionalPoll::etag('shifts.running', [
            $person, implode(',', $stationIds), $input->stationId(), $input->status()?->value, $input->from(), $input->to(), $input->mine(),
            $input->query('page') === null ? 1 : (int) $input->query('page'), $input->perPage(),
            (int) ($stamp->shifts ?? 0), (string) ($stamp->moved ?? ''), (int) ($stamp->versions ?? 0),
        ]);

        return ConditionalPoll::respond($input, $etag, $page);
    }

    public function show(Request $input, ShiftInstance $shift): JsonResponse
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', Station::query()->withTrashed()->findOrFail($shift->station_id));

        return response()->json($this->presenter->one($shift));
    }

    public function store(ScheduleShiftRequest $input, ScheduleShift $schedule): JsonResponse
    {
        $outcome = $schedule->handle(
            $input->clientUuid(),
            $this->station($input->stationId()),
            $input->templateId(),
            $input->onDate(),
            $input->userIds(),
        );

        return response()->json($this->presenter->one($outcome->shift), $outcome->created ? 201 : 200);
    }

    public function replaceRoster(ReplaceRosterRequest $input, ShiftInstance $shift, ReplaceRoster $replace): JsonResponse
    {
        return response()->json($this->presenter->one($replace->handle($shift, $input->userIds())));
    }

    public function cancel(CancelShiftRequest $input, ShiftInstance $shift, CancelShift $cancel): JsonResponse
    {
        return response()->json($this->presenter->one($cancel->handle($shift, $input->reason())));
    }

    public function join(Request $input, ShiftInstance $shift, JoinShift $join): JsonResponse
    {
        return response()->json($this->presenter->one($join->handle($shift)));
    }

    public function leave(Request $input, ShiftInstance $shift, LeaveShift $leave): JsonResponse
    {
        return response()->json($this->presenter->one($leave->handle($shift)));
    }

    public function close(Request $input, ShiftInstance $shift, CloseShift $close, CountReads $reads, CountPresenter $counts): JsonResponse
    {
        $closed = $close->handle($shift);

        return response()->json(['shift' => $this->presenter->one($closed), 'counts' => $counts->many($reads->latest($closed, CountType::Closing))]);
    }

    /**
     * GET /shifts/{shift}/cash (step 3.5): the till widget's cash in the drawer so far, live from Payments' Totals; polled (ETag, 304, step 3.7d); 422 shifts.cash.no_drawer for a station with no drawer.
     *
     * @response array{opening_total: string, carried_float_total: string|null, cash_sales_total: string, expected_total: string, by_channel: list<array{code: string, name_en: string, name_am: string, receipts: int, sum: string}>, currency: string}
     */
    public function cash(Request $input, ShiftInstance $shift, CashDeclarations $cash, Locations $locations): Response
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', Station::query()->withTrashed()->findOrFail($shift->station_id));
        $drawer = $locations->cashDrawerOf($shift->station_id);
        if (! $drawer instanceof LocationView) {
            throw ShiftError::noDrawer();
        }

        // polled by the till widget (step 3.7d): the figures are two small reads, and the tag is made from them, so it moves whenever a figure does
        $figures = $cash->summary($shift, $drawer);

        return ConditionalPoll::respond($input, ConditionalPoll::etag('shifts.cash', [$shift->id, md5(json_encode($figures, JSON_THROW_ON_ERROR))]), fn (): array => $figures);
    }

    public function summary(Request $input, ShiftInstance $shift, ShiftSummaryReader $summary): JsonResponse
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', Station::query()->withTrashed()->findOrFail($shift->station_id));

        return response()->json($summary->of($shift));
    }

    /**
     * The station of the body: a live station of the branch, else 422 validation.failed on station_id (not a 404: the station is not the resource).
     */
    private function station(int $id): Station
    {
        return Station::query()->find($id) ?? throw ValidationException::withMessages(['station_id' => ['The selected station id is invalid.']]);
    }

    /**
     * @param  Builder<ShiftInstance>  $query
     */
    private function page(ListShiftsRequest $input, Builder $query): JsonResponse
    {
        $paginator = $query->orderByDesc('date')->orderByDesc('id')->paginate($input->perPage());

        return response()->json([
            'data' => $this->presenter->many($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
