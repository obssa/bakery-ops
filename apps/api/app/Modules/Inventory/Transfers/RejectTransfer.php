<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Exceptions\TransferError;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use Carbon\CarbonImmutable;

/**
 * POST /transfers/{transfer}/reject (phase-2 plan section 4): the store says no, with a reason ("the sack is for the
 * wedding cake"). One AuditedAction (transfer.reject): it writes no movement and touches no stock. The person must hold
 * transfers.confirm and work at the source's station, like the one who confirms. A rejected transfer answers a second
 * reject with itself, keeping the first reason, and writes nothing (not even an audit row); a confirmed one is 409
 * inventory.transfer.confirmed (the stock moved; it is put back with a new transfer or an adjustment, rule 4). The
 * transfer row is locked, so a reject racing a confirm runs after it and is refused.
 */
final class RejectTransfer
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly TransferAccess $access,
        private readonly TransferAnnouncer $announcer,
    ) {}

    /**
     * @throws TransferError inventory.transfer.source_forbidden (403), inventory.transfer.confirmed (409)
     */
    public function handle(Transfer $transfer, string $reason): Transfer
    {
        $person = $this->access->person();
        $this->access->assertMayDecideFrom($person, Location::query()->withTrashed()->findOrFail($transfer->from_location_id));
        if (self::settled($transfer)) {
            return $transfer;
        }

        try {
            return $this->audit->run(function (Transfer $transfer) use ($person, $reason): Transfer {
                TransferLock::refresh($transfer);
                if (self::settled($transfer)) {
                    throw AlreadyDecided::reached();
                }
                $transfer->forceFill([
                    'status' => $transfer->status->transitionTo(TransferStatus::Rejected),
                    'reject_reason' => $reason,
                    'decided_by_user_id' => $person->id,
                    'decided_at' => CarbonImmutable::now()->startOfSecond(),
                    'version' => $transfer->version + 1,
                ])->save();
                $this->announcer->decided($transfer);

                return $transfer;
            }, $transfer, 'transfer.reject', ['reason' => $reason]);
        } catch (AlreadyDecided) {
            return Transfer::query()->findOrFail($transfer->id);
        }
    }

    /**
     * True when the transfer is already rejected (it answers itself); a confirmed one is refused.
     *
     * @throws TransferError inventory.transfer.confirmed (409)
     */
    private static function settled(Transfer $transfer): bool
    {
        return match ($transfer->status) {
            TransferStatus::Rejected => true,
            TransferStatus::Confirmed => throw TransferError::confirmed(),
            TransferStatus::Requested => false,
        };
    }
}
