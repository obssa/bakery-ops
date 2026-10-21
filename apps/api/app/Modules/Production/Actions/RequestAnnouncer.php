<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Events\ProductionAssigned;
use App\Shared\Events\ProductionRequested;

/**
 * Builds the two production events from a request (the product and the station named in both languages, the quantity in the
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
     * @return array{0: string, 1: string, 2: string} the product names in English and Amharic, and its base unit code
     */
    private function product(ProductionRequest $request): array
    {
        $summary = $this->items->summaries([$request->product_item_id])[$request->product_item_id] ?? null;

        return $summary === null ? ['', '', ''] : [$summary->nameEn, $summary->nameAm, $summary->unit];
    }
}
