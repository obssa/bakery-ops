<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
use App\Modules\Inventory\Transfers\RejectTransfer;
use App\Modules\Inventory\Transfers\RequestTransfer;
use App\Shared\Values\Quantity;

/**
 * manifest inventory.provides: "Transfers::request / confirm / reject" (phase-2 plan section 6 item 3; contract
 * production->inventory): the way a station asks the store for stock and the store hands it over, for Production's "request
 * transfer" on a recipe card as well as for the transfers endpoints, which use the same actions.
 *   request: writes a `requested` row and NO movement; the quantity is in the item's base unit, the source defaults to the
 *     branch store; a client UUID already used answers that transfer. The requester must work at the destination's station
 *     (or at every station).
 *   confirm: under the transfer's lock and the balance locks, checks the source's `available`, then writes the two `transfer`
 *     movements in one Ledger::appendMany; less available than asked is TransferError inventory.transfer.insufficient (409),
 *     nothing written, the row stays `requested`. Confirming a confirmed transfer writes nothing.
 *   reject: writes no movement; a reason is required.
 * All three are audited actions that need an authenticated actor. Items, locations and transfers are given as models or
 * ids. Resolved per use: the actions carry the request-scoped ActorContext and CurrentBranch (traps.md, Octane).
 */
final class Transfers
{
    public function __construct(
        private readonly RequestTransfer $requestTransfer,
        private readonly ConfirmTransfer $confirmTransfer,
        private readonly RejectTransfer $rejectTransfer,
    ) {}

    /**
     * @param  Quantity|string  $qty  in the item's base unit
     */
    public function request(
        Item|int $item,
        Location|int $to,
        Quantity|string $qty,
        string $clientUuid,
        Location|int|null $from = null,
        ?string $note = null,
        ?int $productionRequestId = null,
    ): Transfer {
        return $this->requestTransfer->handle($clientUuid, $item, $to, $qty, null, $from, $note, $productionRequestId)[0];
    }

    public function confirm(Transfer|int $transfer): Transfer
    {
        return $this->confirmTransfer->handle(self::fresh($transfer));
    }

    public function reject(Transfer|int $transfer, string $reason): Transfer
    {
        return $this->rejectTransfer->handle(self::fresh($transfer), $reason);
    }

    /** The transfer as the database holds it: a stale model must not decide what a faster caller already decided. */
    private static function fresh(Transfer|int $transfer): Transfer
    {
        return Transfer::query()->findOrFail($transfer instanceof Transfer ? $transfer->id : $transfer);
    }
}
