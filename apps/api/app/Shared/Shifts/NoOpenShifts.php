<?php

declare(strict_types=1);

namespace App\Shared\Shifts;

/** The binding until Shifts exists (and in any environment without it): no shift runs anywhere, nothing is read. */
final class NoOpenShifts implements OpenShifts
{
    public function stampFor(int $stationId): ?int
    {
        return null;
    }

    public function openAt(int $stationId, bool $lock = false): ?int
    {
        return null;
    }

    public function isOpen(int $shiftId, bool $lock = false): bool
    {
        return false;
    }

    public function currentFor(int $stationId): ?array
    {
        return null;
    }
}
