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

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
