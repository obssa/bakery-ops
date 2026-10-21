<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal the reorder rule actions make, as a stable message_key (lang/<locale>/inventory.php, rule 9) plus
 * the HTTP status it maps to and what it is about. Thrown inside an AuditedAction it rolls the whole write back; Laravel renders
 * it through render(). All four are 422: the request names something the branch cannot take as a rule.
 */
final class ReorderError extends RuntimeException
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

    /** The item already has a live rule at that place (or branch-wide): change that one. */
    public static function exists(int $ruleId): self
    {
        return new self('inventory.reorder.exists', 422, ['rule_id' => $ruleId]);
    }

    /** notify_roles names something that is neither a role of the permission registry nor "station". */
    public static function roleUnknown(string $role): self
    {
        return new self('inventory.reorder.role_unknown', 422, ['role' => $role]);
    }

    /** Not a live item of this branch, or a made-to-order product (it holds no stock to run low). */
    public static function itemUnknown(int $itemId): self
    {
        return new self('inventory.reorder.item_unknown', 422, ['item_id' => $itemId]);
    }

    /** Not a live stock location of this branch (the cash drawer holds money, not stock). */
    public static function locationUnknown(int $locationId): self
    {
        return new self('inventory.reorder.location_unknown', 422, ['location_id' => $locationId]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
