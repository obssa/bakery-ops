<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Events\TransferDecided;
use App\Shared\Events\TransferRequested;

/**
 * Builds the two transfer events from a transfer (item and places named in both languages, the quantity in the item's base
 * unit) and dispatches them. Called inside the action's transaction: both events are ShouldDispatchAfterCommit, so they
 * leave only when the outermost transaction commits and never when it rolls back.
 */
final class TransferAnnouncer
{
    public function __construct(private readonly Units $units) {}

    public function requested(Transfer $transfer): void
    {
        $transfer->loadMissing(['item', 'fromLocation', 'toLocation']);

        event(new TransferRequested(
            $transfer->branch_id,
            $transfer->id,
            $transfer->item->id,
            $transfer->item->name_en,
            $transfer->item->name_am,
            $transfer->fromLocation->id,
            $transfer->fromLocation->name_en,
            $transfer->fromLocation->name_am,
            $transfer->fromLocation->station_id,
            $transfer->toLocation->id,
            $transfer->toLocation->name_en,
            $transfer->toLocation->name_am,
            $transfer->toLocation->station_id,
            $transfer->qty->toString(),
            $this->units->baseOf($transfer->item->unit_id)->code,
            $transfer->requested_by_user_id,
            $transfer->production_request_id,
        ));
    }

    public function decided(Transfer $transfer): void
    {
        $transfer->loadMissing(['item', 'fromLocation', 'toLocation']);

        event(new TransferDecided(
            $transfer->branch_id,
            $transfer->id,
            $transfer->item->id,
            $transfer->item->name_en,
            $transfer->item->name_am,
            $transfer->fromLocation->id,
            $transfer->fromLocation->name_en,
            $transfer->fromLocation->name_am,
            $transfer->fromLocation->station_id,
            $transfer->toLocation->id,
            $transfer->toLocation->name_en,
            $transfer->toLocation->name_am,
            $transfer->toLocation->station_id,
            $transfer->qty->toString(),
            $this->units->baseOf($transfer->item->unit_id)->code,
            $transfer->requested_by_user_id,
            $transfer->status->value,
            $transfer->reject_reason,
            (int) $transfer->decided_by_user_id,
        ));
    }
}
