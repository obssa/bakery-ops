<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Events\BatchDone;
use App\Shared\Events\ProductionAssigned;
use App\Shared\Events\ProductionRequested;

/**
 * Builds the production events from a request (and, for BatchDone, its batch) (the product and the station named in both languages, the quantity in the
 * product base unit) and dispatches them. Called inside the action's transaction: both events are ShouldDispatchAfterCommit,
 * so they leave only when the outermost transaction commits and never when it rolls back.
 */
final class RequestAnnouncer
{
    public function __construct(private readonly Items $items) {}

    public function requested(ProductionRequest $request): void
    {
        [$nameEn, $nameAm, $unit] = $this->product($request);
        $station = $request->station;

        event(new ProductionRequested(
            $request->branch_id,
            $request->id,
            $request->product_item_id,
            $nameEn,
            $nameAm,
            $request->requested_qty->toString(),
            $unit,
            $station->id,
            $station->name_en,
            $station->name_am,
            $request->reason->value,
            $request->priority->value,
            $request->due_at?->toIso8601String(),
            $request->requested_by_user_id,
        ));
    }

    public function assigned(ProductionRequest $request, int $assignedBy): void
    {
        [$nameEn, $nameAm, $unit] = $this->product($request);
        $station = $request->station;

        event(new ProductionAssigned(
            $request->branch_id,
            $request->id,
            $request->product_item_id,
            $nameEn,
            $nameAm,
            $request->requested_qty->toString(),
            $unit,
            $station->id,
            $station->name_en,
            $station->name_am,
            (int) $request->assigned_to_user_id,
            $assignedBy,
            $request->version,
        ));
    }

    /**
     * BatchDone for a batch that was just finished: status BatchDone::PENDING (an overseer must sign off) or BatchDone::CONFIRMED.
     * The request carries the person who asked and the baker, which Notifications may not read from Production's tables.
     */
    public function batchDone(ProductionRequest $request, ProductionBatch $batch, string $status): void
    {
        [$nameEn, $nameAm, $unit] = $this->product($request);
        $station = $request->station;

        event(new BatchDone(
            $request->branch_id,
            $batch->id,
            $request->id,
            $status,
            $request->product_item_id,
            $nameEn,
            $nameAm,
            (string) $batch->produced_qty,
            (string) $batch->waste_qty,
            $unit,
            $station->id,
            $station->name_en,
            $station->name_am,
            (int) $batch->finished_by_user_id,
            $batch->confirmed_by_user_id,
            $request->requested_by_user_id,
            $request->assigned_to_user_id,
            $batch->send_back_count,
        ));
    }

    /**
     * @return array{0: string, 1: string, 2: string} the product names in English and Amharic, and its base unit code
     */
    private function product(ProductionRequest $request): array
    {
        $summary = $this->items->summaries([$request->product_item_id])[$request->product_item_id] ?? null;

        return $summary === null ? ['', '', ''] : [$summary->nameEn, $summary->nameAm, $summary->unit];
    }
}
