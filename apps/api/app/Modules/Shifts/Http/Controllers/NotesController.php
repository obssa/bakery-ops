<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Shifts\Actions\WriteHandoverNote;
use App\Modules\Shifts\Handover\NotePresenter;
use App\Modules\Shifts\Http\Requests\ListNotesRequest;
use App\Modules\Shifts\Http\Requests\StoreHandoverNoteRequest;
use App\Modules\Shifts\Models\HandoverNote;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Station;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handover notes (phase-3 plan section 4, "Handover and replay (3.6)"). {shift} binds through the BranchScoped model, so another branch's
 * shift is 404.
 *
 *   GET  /shifts/{shift}/notes   200 {data: [Note], last_id} oldest first; ?after_id= is the cursor of the last poll. Polled (ETag, 304) through
 *                                OverlapCursor: `last_id` is held back by the overlap window, so a note that committed late is delivered
 *                                again and never skipped; clients de-duplicate by id. shifts.view at the shift's station
 *   POST /shifts/{shift}/notes   201 the Note; 200 when the client_uuid was already used (WriteHandoverNote)
 */
final class NotesController
{
    public function __construct(
        private readonly ShiftAccess $access,
        private readonly NotePresenter $presenter,
    ) {}

    public function index(ListNotesRequest $input, ShiftInstance $shift): Response
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', Station::query()->withTrashed()->findOrFail($shift->station_id));

        $query = HandoverNote::query()->where('shift_instance_id', $shift->id);
        $cursor = OverlapCursor::fromConfig();
        $since = $input->afterId();
        $etag = ConditionalPoll::etag('shifts.notes', [$shift->id, ...array_values($cursor->fingerprint($query, $since))]);

        return ConditionalPoll::respond($input, $etag, fn (): array => [
            'data' => $this->presenter->many($cursor->after(clone $query, $since)->get()),
            'last_id' => $cursor->next($query, $since),
        ]);
    }

    public function store(StoreHandoverNoteRequest $input, ShiftInstance $shift, WriteHandoverNote $write): JsonResponse
    {
        $outcome = $write->handle($shift, $input->clientUuid(), $input->text(), $input->toUserId());

        return response()->json($this->presenter->one($outcome->note), $outcome->created ? 201 : 200);
    }
}
