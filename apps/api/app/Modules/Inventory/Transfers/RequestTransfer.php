<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Transfers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Exceptions\TransferError;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\NumericOverflow;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * POST /transfers (phase-2 plan section 4): someone asks for stock to be moved to a place, usually the store to a station.
 * One AuditedAction (transfer.request) writes a `requested` row and NO movement: stock moves only when someone at the source
 * confirms (ConfirmTransfer). Everything is checked before anything is written:
 *   - the destination and the source (default: the branch store) are live locations of this branch and differ;
 *   - the item is live and kept in stock (not a made-to-order product);
 *   - the quantity, typed in $unitId or already in base units, is positive in base units and fits the column;
 *   - the requester works at the destination's station, or at every station (TransferAccess).
 * The request does not check the source's stock: the answer to "is there enough" is only true at the confirm, under the lock.
 * The client UUID makes an offline replay safe even when the Idempotency-Key was lost (ADR 0003): a client UUID already used
 * answers that transfer as it stands, writing and announcing nothing.
 */
final class RequestTransfer
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Locations $locations,
        private readonly Units $units,
        private readonly TransferAccess $access,
        private readonly TransferAnnouncer $announcer,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return array{0: Transfer, 1: bool} the transfer, and whether this call created it
     *
     * @throws TransferError inventory.transfer.location_unknown, same_location, item_not_stockable (422), destination_forbidden (403)
     * @throws ValidationException a quantity that does not fit, or rounds to zero
     */
    public function handle(
        string $clientUuid,
        Item|int $item,
        Location|int $to,
        Quantity|string $qty,
        ?int $unitId = null,
        Location|int|null $from = null,
        ?string $note = null,
        ?int $productionRequestId = null,
    ): array {
        $existing = Transfer::query()->where('client_uuid', $clientUuid)->first();
        if ($existing instanceof Transfer) {
            return [$existing, false];
        }

        $person = $this->access->person();
        $destination = $this->location($to, 'to_location_id');
        $source = $from === null
            ? ($this->locations->store() ?? throw TransferError::locationUnknown('from_location_id'))
            : $this->location($from, 'from_location_id');
        if ($source->id === $destination->id) {
            throw TransferError::sameLocation();
        }
        $itemModel = $item instanceof Item ? $item : Item::query()->find($item);
        if (! $itemModel instanceof Item || ! $itemModel->is_active || $itemModel->fulfilment === Fulfilment::ToOrder) {
            throw TransferError::itemNotStockable($item instanceof Item ? $item->id : $item);
        }
        $base = $this->baseQuantity(Quantity::of($qty), $unitId, $itemModel);
        $this->access->assertMayRequestInto($person, $destination);

        try {
            $transfer = $this->audit->run(function () use ($clientUuid, $itemModel, $source, $destination, $base, $note, $productionRequestId, $person): Transfer {
                $stamp = $this->actor->stamp();
                $transfer = new Transfer;
                $transfer->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $clientUuid,
                    'item_id' => $itemModel->id,
                    'from_location_id' => $source->id,
                    'to_location_id' => $destination->id,
                    'qty' => $base,
                    'status' => TransferStatus::Requested,
                    'note' => $note,
                    'production_request_id' => $productionRequestId,
                    'requested_by_user_id' => $person->id,
                    'requested_at' => CarbonImmutable::now()->startOfSecond(),
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'shift_instance_id' => $stamp['shift_instance_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'version' => 1,
                ])->save();
                $this->announcer->requested($transfer);

                return $transfer;
            }, null, 'transfer.request');
        } catch (UniqueConstraintViolationException $e) {
            // the same request arrived twice at once under different keys: the other one wrote it
            $existing = Transfer::query()->where('client_uuid', $clientUuid)->first();
            if (! $existing instanceof Transfer) {
                throw $e;
            }

            return [$existing, false];
        }

        return [$transfer, true];
    }

    private function location(Location|int $location, string $field): Location
    {
        $found = $location instanceof Location ? $location : Location::query()->find($location);

        return $found instanceof Location ? $found : throw TransferError::locationUnknown($field);
    }

    /**
     * The quantity in the item's base unit: typed in $unitId (which must measure the same thing as the item), else already
     * in base units. A value that does not fit NUMERIC(14,4), or rounds to zero, is a 422 on `qty`.
     */
    private function baseQuantity(Quantity $qty, ?int $unitId, Item $item): Quantity
    {
        try {
            $base = $unitId === null ? $qty : $this->units->toBaseOf($qty, $unitId, $item->unit_id, 'unit_id');
        } catch (NumericOverflow) {
            throw self::invalidQty('inventory.transfer.qty_too_large');
        }
        if (! $base->isPositive()) {
            throw self::invalidQty('inventory.transfer.qty_too_small');
        }

        return $base;
    }

    private static function invalidQty(string $key): ValidationException
    {
        $message = trans($key);

        return ValidationException::withMessages(['qty' => [is_string($message) ? $message : $key]]);
    }
}
