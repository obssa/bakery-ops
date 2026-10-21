<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Exceptions\TransferError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use Carbon\CarbonImmutable;

/**
 * POST /transfers/{transfer}/confirm (phase-2 plan sections 4, 6 item 3 and 10.4): the stock is handed over. One
 * AuditedAction (transfer.confirm), one transaction, in this order:
 *   1. the person must hold transfers.confirm and work at the source's station (TransferAccess);
 *   2. lock the transfer row (TransferLock) and check it is still `requested`;
 *   3. lock both balance rows through Ledger::lock (ascending (item_id, location_id), a missing destination row is created at
 *      zero first, never gap-locked) and read the source's `available` (on hand minus reserved) under those locks: less than
 *      the quantity is 409 inventory.transfer.insufficient, nothing is written and the row stays `requested`;
 *   4. one Ledger::appendMany of MovementIntent::transfer (out of the source, into the destination): both sides are
 *      written or neither, and the ledger itself refuses to take either balance below zero;
 *   5. out_movement_id and in_movement_id, status confirmed, the decision, version + 1, and TransferDecided (after commit).
 * The lock order of the whole system is therefore: the purchase or the transfer, stock_balances ascending, item_costs
 * ascending. A transfer does not touch item_costs: the moving average is per item, whatever the place.
 * Confirming a confirmed transfer answers it as it stands, writing nothing (not even an audit row); a rejected one is 409.
 */
final class ConfirmTransfer
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Ledger $ledger,
        private readonly TransferAccess $access,
        private readonly TransferAnnouncer $announcer,
    ) {}

    /**
     * @throws TransferError inventory.transfer.source_forbidden (403), inventory.transfer.rejected (409),
     *                       inventory.transfer.insufficient (409)
     */
    public function handle(Transfer $transfer): Transfer
    {
        $person = $this->access->person();
        $this->access->assertMayDecideFrom($person, Location::query()->withTrashed()->findOrFail($transfer->from_location_id));
        if (self::settled($transfer)) {
            return $transfer;
        }

        try {
            return $this->audit->run(function (Transfer $transfer) use ($person): Transfer {
                TransferLock::refresh($transfer);
                if (self::settled($transfer)) {
                    throw AlreadyDecided::reached();
                }

                $levels = $this->ledger->lock([[$transfer->item_id, $transfer->from_location_id], [$transfer->item_id, $transfer->to_location_id]]);
                $available = $levels[Ledger::key($transfer->item_id, $transfer->from_location_id)]->available();
                if ($available->isLessThan($transfer->qty)) {
                    throw TransferError::insufficient($transfer->item_id, $transfer->from_location_id, $available->toString(), $transfer->qty->toString());
                }

                [$out, $in] = $this->ledger->appendMany(MovementIntent::transfer(
                    $transfer->item_id,
                    $transfer->from_location_id,
                    $transfer->to_location_id,
                    $transfer->qty,
                    'transfer',
                    $transfer->id,
                ));

                $transfer->forceFill([
                    'status' => $transfer->status->transitionTo(TransferStatus::Confirmed),
                    'decided_by_user_id' => $person->id,
                    'decided_at' => CarbonImmutable::now()->startOfSecond(),
                    'out_movement_id' => $out->id,
                    'in_movement_id' => $in->id,
                    'version' => $transfer->version + 1,
                ])->save();
                $this->announcer->decided($transfer);

                return $transfer;
            }, $transfer, 'transfer.confirm');
        } catch (AlreadyDecided) {
            return Transfer::query()->findOrFail($transfer->id);
        }
    }

    /**
     * True when the transfer is already confirmed (it answers itself); a rejected one is refused.
     *
     * @throws TransferError inventory.transfer.rejected (409)
     */
    private static function settled(Transfer $transfer): bool
    {
        return match ($transfer->status) {
            TransferStatus::Confirmed => true,
            TransferStatus::Rejected => throw TransferError::rejected(),
            TransferStatus::Requested => false,
        };
    }
}
