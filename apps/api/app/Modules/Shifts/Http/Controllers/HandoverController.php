<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Shifts\Contracts\Replay;
use App\Modules\Shifts\Exceptions\ShiftError;
use App\Modules\Shifts\Handover\HandoverPanel;
use App\Modules\Shifts\Handover\ReplayPresenter;
use App\Modules\Shifts\Http\Requests\ReplayRequest;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The handover panel and the replay (phase-3 plan section 4, "Handover and replay (3.6)").
 *
 *   GET /stations/{station}/handover   200 {previous, notes, previous_closing, pending_counts} (HandoverPanel); shifts.view at the station;
 *                                      422 shifts.station.not_tracked
 *   GET /replay                        200 the station at a moment (Contracts\Replay, ReplayPresenter); shifts.replay (admin, manager);
 *                                      ?station_id=, ?at=, ?nonzero=0; 422 shifts.replay.future, 422 validation.failed
 */
final class HandoverController
{
    public function __construct(private readonly ShiftAccess $access) {}

    public function show(Request $input, Station $station, HandoverPanel $panel): JsonResponse
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', $station);
        $this->access->assertTracked($station);

        return response()->json($panel->of($station, $person));
    }

    public function replay(ReplayRequest $input, Replay $replay, ReplayPresenter $presenter): JsonResponse
    {
        $station = $input->station();
        $at = $input->instant();
        if ($at->greaterThan(CarbonImmutable::now())) {
            throw ShiftError::replayFuture($at->toIso8601String());
        }

        return response()->json($presenter->present($replay->at($station, $at, $input->nonzero()), $station));
    }
}
