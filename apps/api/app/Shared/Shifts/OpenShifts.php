<?php

declare(strict_types=1);

namespace App\Shared\Shifts;

/**
 * Which shift is running at a station, asked without importing the Shifts module (dependency-graph.md, cycle 1; the third
 * use of the technique of StationPresenceResolver and ShiftAttacher): the interface lives in Shared, where the writer
 * (Inventory's ledger check) and the reader (Identity's ResolveActor) may both import it, and Shifts rebinds it
 * (step 3.2b). Until then NoOpenShifts answers that nothing runs. Bound scoped(): an implementation may hold request
 * state and must not keep it into the next request (traps.md, Octane).
 *
 * Shift ids are plain ints: Shared never names a Shifts table. "Running" is open or counting (a frozen station still
 * stamps its own count's adjustments); "open" is status open only.
 */
interface OpenShifts
{
    /** The id of the shift running at the station (open or counting), used to stamp a request's writes; null when none. */
    public function stampFor(int $stationId): ?int;

    /**
     * The id of the shift in status open at the station, null when none. $lock takes a shared row lock on it for the rest
     * of the caller's transaction, so a close cannot freeze the station between the check and the write.
     */
    public function openAt(int $stationId, bool $lock = false): ?int;

    /** Is this shift in status open? $lock as in openAt(). */
    public function isOpen(int $shiftId, bool $lock = false): bool;

    /**
     * The running shift of the station as the client shows it (GET /me work_stations), null when none.
     *
     * @return array{id: int, status: string, date: string, opened_at: string|null}|null
     */
    public function currentFor(int $stationId): ?array;
}
