<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Enums\TransferStatus;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal the transfer actions make, as a stable message_key (lang/<locale>/inventory.php, rule 9) plus
 * the HTTP status it maps to and what it is about (the field, the stock, the transfer's status). Thrown inside an
 * AuditedAction it rolls the whole write back; Laravel renders it through render().
 */
final class TransferError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    /** The person acting by short code does not hold the permission the logged-in account passed the route with. */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    /** A transfer moves stock between two different places. */
    public static function sameLocation(): self
    {
        return new self('inventory.transfer.same_location', 422);
    }

    /** Only items that are kept in stock are moved; a made-to-order product never is, and the item must be live in this branch. */
    public static function itemNotStockable(int $itemId): self
    {
        return new self('inventory.transfer.item_not_stockable', 422, ['item_id' => $itemId]);
    }

    /** A place that is not a live location of this branch (or no store to default the source to). */
    public static function locationUnknown(string $field): self
    {
        return new self('inventory.transfer.location_unknown', 422, ['field' => $field]);
    }

    /** The requester does not work at the destination's station and does not work at every station. */
    public static function destinationForbidden(): self
    {
        return new self('inventory.transfer.destination_forbidden', 403);
    }

    /** The person deciding does not work at the source's station and does not work at every station. */
    public static function sourceForbidden(): self
    {
        return new self('inventory.transfer.source_forbidden', 403);
    }

    /** The source has less available (on hand minus reserved) than was asked; nothing was written. */
    public static function insufficient(int $itemId, int $locationId, string $available, string $requested): self
    {
        return new self('inventory.transfer.insufficient', 409, [
            'item_id' => $itemId, 'location_id' => $locationId, 'available' => $available, 'requested' => $requested,
        ]);
    }

    /** A confirmed transfer cannot be rejected: the stock moved (put it back with a transfer or an adjustment, rule 4). */
    public static function confirmed(): self
    {
        return new self('inventory.transfer.confirmed', 409, ['status' => TransferStatus::Confirmed->value]);
    }

    /** A rejected transfer cannot be confirmed: ask again with a new request. */
    public static function rejected(): self
    {
        return new self('inventory.transfer.rejected', 409, ['status' => TransferStatus::Rejected->value]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
