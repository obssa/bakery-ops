<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Controllers;

use App\Modules\Identity\Contracts\People;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Shifts\Actions\ApproveCount;
use App\Modules\Shifts\Actions\CosignCount;
use App\Modules\Shifts\Actions\RejectCount;
use App\Modules\Shifts\Actions\SetCashDeclaration;
use App\Modules\Shifts\Actions\SetCountLines;
use App\Modules\Shifts\Actions\SubmitCount;
use App\Modules\Shifts\Actions\UnlockCount;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Counts\CountPresenter;
use App\Modules\Shifts\Counts\CountQueue;
use App\Modules\Shifts\Counts\CountReads;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Http\Requests\ApproveCountRequest;
use App\Modules\Shifts\Http\Requests\CosignCountRequest;
use App\Modules\Shifts\Http\Requests\ListCountsRequest;
use App\Modules\Shifts\Http\Requests\RejectCountRequest;
use App\Modules\Shifts\Http\Requests\SetCashDeclarationRequest;
use App\Modules\Shifts\Http\Requests\SetCountLinesRequest;
use App\Modules\Shifts\Http\Requests\UnlockCountRequest;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Support\ShiftAccess;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The counts of a shift (phase-3 plan section 4, "Counts", step 3.3a). Every write is an AuditedAction in the action classes behind
 * an Idempotency-Key; another branch's shift or count is 404 through the BranchScoped route binding (rule 8). shifts.view reads and
 * shifts.count writes, both at the shift's station (403 shifts.station.forbidden; the admin and manager work at every station).
 *
 *   GET /shifts/{shift}/counts          200 {data: [Count]} every revision of the shift's counts, oldest first, with their lines
 *   GET /stock-counts/{count}           200 the Count with its lines side by side; polled (ETag, 304): the tag moves with the count and,
 *                                       while the count is open to change, with the ledger (the draft's expected figure is live)
 *   PUT /stock-counts/{count}/lines     200 the Count; 409 shifts.count.not_draft; 422 shifts.count.qty_invalid, item_unknown, reason.unknown
 *   PUT  /stock-counts/{count}/cash     200 the Count with its `cash` block (step 3.5; the till's cash declaration, draft only); 409 / 422 as SetCashDeclaration says
 *   POST /stock-counts/{count}/submit   200 the Count; a repeat answers it; 409 / 422 as SubmitCount says
 *   POST /stock-counts/{count}/approve  200 the Count (step 3.3b; shifts.approve): the adjustments are written, a repeat answers it; 409 as ApproveCount says
 *   POST /stock-counts/{count}/reject   200 the rejected Count plus `recount`, the prefilled draft revision (the action decides: a co-signer while the count
 *                                       waits for a co-signature, shifts.approve while it waits for a decision); 409 / 422 as RejectCount says
 *   POST /stock-counts/{count}/cosign   200 the Count (step 3.3c; the overseer of the station or a shifts.cosign holder, never the counter); 403 / 409 as CosignCount says
 *   POST /stock-counts/{count}/unlock   200 {unlocked, revision} (step 3.4; shifts.unlock, the admin): the locked Count stays locked and gets superseded_by_id, the
 *                                       revision is a new draft (supersede_cause unlock); 409 shifts.count.not_locked / already_superseded
 *   GET  /stock-counts                  200 {data: [CountSummary + station, shift, variance_lines], counts: {awaiting_cosign, pending_approval}, server_time}: the approval
 *                                       queue of the stations the caller works at; ?status=, ?station_id=, ?mine=1, ?limit=; polled (ETag, 304)
 */
final class CountsController
{
    public function __construct(
        private readonly CountPresenter $presenter,
        private readonly ShiftAccess $access,
        private readonly People $people,
    ) {}

    public function forShift(Request $input, ShiftInstance $shift, CountReads $reads): JsonResponse
    {
        $this->assertCanRead($shift);

        return response()->json(['data' => $this->presenter->many($reads->all($shift))]);
    }

    public function index(ListCountsRequest $input, CountQueue $queue, Stock $stock): Response
    {
        $person = $this->access->person('shifts.view');
        $stationIds = $this->people->workStationIds($person);
        $scope = $queue->scope($stationIds, $input->stationId(), $input->mine() ? $person : null);

        // the polled feed: a few cheap numbers say whether anything changed, the ledger included (moved_since reads it) (ADR 0009)
        $stamp = (clone $scope)->toBase()->selectRaw('count(*) as total, max(updated_at) as moved, coalesce(sum(version), 0) as versions')->first();
        $ledger = $stock->fingerprint();
        $etag = ConditionalPoll::etag('shifts.queue', [
            $person, implode(',', $stationIds), $input->stationId(), $input->status()?->value, $input->mine(), $input->limit(),
            (int) ($stamp->total ?? 0), (string) ($stamp->moved ?? ''), (int) ($stamp->versions ?? 0), $ledger['last_movement_id'], $ledger['settling'],
        ]);

        return ConditionalPoll::respond($input, $etag, fn (): array => [
            'data' => $queue->rows($scope, $input->status(), $input->limit()),
            'counts' => $queue->totals($scope),
            'server_time' => CarbonImmutable::now()->toIso8601ZuluString(),
        ]);
    }

    public function show(Request $input, StockCount $count, Stock $stock, Locks $locks): Response
    {
        $shift = ShiftInstance::query()->findOrFail($count->shift_instance_id);
        $this->assertCanRead($shift);

        $moving = $count->status === CountStatus::Draft || $count->status->isUnresolved() || $count->status === CountStatus::Approved;
        $ledger = $moving ? $stock->fingerprint() : null;
        $etag = ConditionalPoll::etag('shifts.count', [
            $count->id,
            $count->version,
            $count->status->value,
            $count->updated_at?->getTimestamp(),
            $ledger['last_movement_id'] ?? null,
            $ledger['settling'] ?? null,
            $count->status === CountStatus::Draft ? $locks->unresolvedAt($count->location_id, $count->id)?->id : null,
        ]);

        return ConditionalPoll::respond($input, $etag, fn (): array => $this->presenter->one($count));
    }

    public function lines(SetCountLinesRequest $input, StockCount $count, SetCountLines $set): JsonResponse
    {
        return response()->json($this->presenter->one($set->handle($count, $input->lines(), $input->confirmAll())));
    }

    public function cash(SetCashDeclarationRequest $input, StockCount $count, SetCashDeclaration $set): JsonResponse
    {
        return response()->json($this->presenter->one($set->handle($count, $input->declaration())));
    }

    public function submit(Request $input, StockCount $count, SubmitCount $submit): JsonResponse
    {
        return response()->json($this->presenter->one($submit->handle($count)));
    }

    public function approve(ApproveCountRequest $input, StockCount $count, ApproveCount $approve): JsonResponse
    {
        return response()->json($this->presenter->one($approve->handle($count, $input->note())));
    }

    public function cosign(CosignCountRequest $input, StockCount $count, CosignCount $cosign): JsonResponse
    {
        return response()->json($this->presenter->one($cosign->handle($count, $input->note())));
    }

    public function reject(RejectCountRequest $input, StockCount $count, RejectCount $reject): JsonResponse
    {
        [$rejected, $recount] = $reject->handle($count, $input->note(), $input->lineIds());
        [$row, $recountRow] = $this->presenter->many([$rejected, $recount]);

        return response()->json($row + ['recount' => $recountRow]);
    }

    public function unlock(UnlockCountRequest $input, StockCount $count, UnlockCount $unlock): JsonResponse
    {
        [$unlocked, $revision] = $unlock->handle($count, $input->reason());
        [$old, $new] = $this->presenter->many([$unlocked, $revision]);

        return response()->json(['unlocked' => $old, 'revision' => $new]);
    }

    private function assertCanRead(ShiftInstance $shift): void
    {
        $person = $this->access->person('shifts.view');
        $this->access->assertAt($person, 'shifts.view', Station::query()->withTrashed()->findOrFail($shift->station_id));
    }
}
