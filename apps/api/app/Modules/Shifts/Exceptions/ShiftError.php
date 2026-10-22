<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal the Shifts actions make, as a stable message_key (lang/<locale>/shifts.php, rule 9) plus the HTTP
 * status it maps to and what it is about (the shift, its status, the window). Thrown inside an AuditedAction it rolls the whole
 * write back; Laravel renders it through render().
 */
final class ShiftError extends RuntimeException
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

    /** The person acting by short code does not hold the permission the logged-in account passed the route with, or does not exist. */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    /** The person does not work at the station and does not work at every station. */
    public static function stationForbidden(): self
    {
        return new self('shifts.station.forbidden', 403);
    }

    /** A station with tracks_shifts false has no shifts at all. */
    public static function stationNotTracked(int $stationId): self
    {
        return new self('shifts.station.not_tracked', 422, ['station_id' => $stationId]);
    }

    /** A shift already runs at the station (opening, open or counting): the one in the way is named. */
    public static function alreadyOpen(int $shiftId, string $status): self
    {
        return new self('shifts.shift.already_open', 409, ['shift_id' => $shiftId, 'status' => $status]);
    }

    /** The template is already scheduled, or running, on that date at that station. */
    public static function alreadyScheduled(int $shiftId): self
    {
        return new self('shifts.shift.already_scheduled', 409, ['shift_id' => $shiftId]);
    }

    /** The shift asked for is not a scheduled one (roster change, start by id). */
    public static function notScheduled(string $status): self
    {
        return new self('shifts.shift.not_scheduled', 409, ['status' => $status]);
    }

    /** A scheduled shift started outside its window (from `starts_at - grace` to `ends_at`) by a person who may not force it. */
    public static function outsideWindow(string $startsAt, string $endsAt): self
    {
        return new self('shifts.shift.outside_window', 422, ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    /** Join and leave need a running shift (opening, open or counting). */
    public static function notRunning(string $status): self
    {
        return new self('shifts.shift.not_running', 409, ['status' => $status]);
    }

    /** Only a scheduled or opening shift is cancelled. */
    public static function notCancellable(string $status): self
    {
        return new self('shifts.shift.not_cancellable', 409, ['status' => $status]);
    }

    /** `force` is the admin's. */
    public static function forceForbidden(): self
    {
        return new self('shifts.start.force_forbidden', 403);
    }

    /** Cancel is the admin's, or the starter's own while the shift is opening. */
    public static function cancelForbidden(): self
    {
        return new self('shifts.cancel.forbidden', 403);
    }

    /** A window that starts and ends at the same time. */
    public static function windowInvalid(): self
    {
        return new self('shifts.template.window_invalid', 422);
    }

    /**
     * @param  list<int>  $stationIds
     */
    public static function templateStationUnknown(array $stationIds): self
    {
        return new self('shifts.template.station_unknown', 422, ['station_ids' => $stationIds]);
    }

    /** The template does not exist, is inactive or deleted, or is not paired with the station. */
    public static function templateNotForStation(int $templateId, int $stationId): self
    {
        return new self('shifts.template.not_for_station', 422, ['template_id' => $templateId, 'station_id' => $stationId]);
    }

    /**
     * Someone named for a roster is not an active person of the branch who may count at the station.
     *
     * @param  list<int>  $userIds
     */
    public static function rosterUserInvalid(array $userIds): self
    {
        return new self('shifts.roster.user_invalid', 422, ['user_ids' => $userIds]);
    }

    /** Close needs an open shift. */
    public static function notOpen(string $status): self
    {
        return new self('shifts.shift.not_open', 409, ['status' => $status]);
    }

    /** The lines of a count change, and a count is handed in, only while it is a draft. */
    public static function countNotDraft(string $status): self
    {
        return new self('shifts.count.not_draft', 409, ['status' => $status]);
    }

    /** The shift is not taking this kind of count now (an opening count needs an opening shift, a closing count a counting one). */
    public static function countShiftNotReady(string $status): self
    {
        return new self('shifts.count.shift_not_ready', 409, ['status' => $status]);
    }

    /** The item is not an item of the branch, or cannot be stocked (switched off, deleted, made to order). */
    public static function itemUnknown(int $itemId): self
    {
        return new self('shifts.count.item_unknown', 422, ['item_id' => $itemId]);
    }

    /** A counted figure that is negative, has more than four decimals, more than ten integer digits, or is not a decimal number. */
    public static function qtyInvalid(int $itemId): self
    {
        return new self('shifts.count.qty_invalid', 422, ['item_id' => $itemId]);
    }

    /**
     * A line was not looked at, or an item with stock is not on the count yet.
     *
     * @param  list<int>  $itemIds
     */
    public static function incomplete(array $itemIds): self
    {
        return new self('shifts.count.incomplete', 422, ['item_ids' => $itemIds]);
    }

    /**
     * Lines whose counted figure differs from the ledger and that carry no reason.
     *
     * @param  list<int>  $itemIds
     */
    public static function reasonRequired(array $itemIds): self
    {
        return new self('shifts.count.reason_required', 422, ['item_ids' => $itemIds]);
    }

    /**
     * Lines whose reason asks for a note and carry none.
     *
     * @param  list<int>  $itemIds
     */
    public static function noteRequired(array $itemIds): self
    {
        return new self('shifts.count.note_required', 422, ['item_ids' => $itemIds]);
    }

    /** Rule R-A: another count at this place is awaiting a co-signature or a decision. */
    public static function blockedByPending(int $countId): self
    {
        return new self('shifts.count.blocked_by_pending', 409, ['count_id' => $countId]);
    }

    /** A decision is made on a count that waits for one (pending_approval; a reject also takes awaiting_cosign). */
    public static function countNotPending(string $status): self
    {
        return new self('shifts.count.not_pending', 409, ['status' => $status]);
    }

    /** The person who counted does not approve their own count (a sole admin included: plan section 10, open question 4). */
    public static function approveSelf(): self
    {
        return new self('shifts.count.approve_self', 409);
    }

    /** The reason does not exist in the branch, or is switched off. */
    public static function reasonUnknown(int $reasonId): self
    {
        return new self('shifts.reason.unknown', 422, ['reason_id' => $reasonId]);
    }

    /** The branch already has a reason with this code (switched off or not). */
    public static function reasonCodeTaken(string $code): self
    {
        return new self('shifts.reason.code_taken', 409, ['code' => $code]);
    }

    /** A count's adjustment would take on_hand below zero (negative stock stays refused, plan section 10.4). */
    public static function adjustmentInsufficient(int $itemId, int $locationId, string $onHand, string $adjustment): self
    {
        return new self('shifts.adjustment.insufficient', 409, ['item_id' => $itemId, 'location_id' => $locationId, 'on_hand' => $onHand, 'adjustment' => $adjustment]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
