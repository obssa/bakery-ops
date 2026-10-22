<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Shifts\OpenShifts;

/**
 * An OpenShifts double for the tests that run before Shifts exists (and for the ledger's enforcement cases after it):
 * the running shift per station id, and a log of every question asked, so a test can also say "never consulted".
 * isOpen() is true for any shift id that runs somewhere in the map, or that is listed as open on its own.
 */
final class FakeOpenShifts implements OpenShifts
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<int, int>  $running  station id => the id of the shift running there
     * @param  list<int>  $alsoOpen  shift ids that are open without being a station's running shift
     */
    public function __construct(private readonly array $running = [], private readonly array $alsoOpen = []) {}

    public function stampFor(int $stationId): ?int
    {
        $this->asked[] = "stampFor:{$stationId}";

        return $this->running[$stationId] ?? null;
    }

    public function openAt(int $stationId, bool $lock = false): ?int
    {
        $this->asked[] = "openAt:{$stationId}";

        return $this->running[$stationId] ?? null;
    }

    public function isOpen(int $shiftId, bool $lock = false): bool
    {
        $this->asked[] = "isOpen:{$shiftId}";

        return in_array($shiftId, $this->running, true) || in_array($shiftId, $this->alsoOpen, true);
    }

    public function currentFor(int $stationId): ?array
    {
        $this->asked[] = "currentFor:{$stationId}";
        $id = $this->running[$stationId] ?? null;

        return $id === null ? null : ['id' => $id, 'status' => 'open', 'date' => '2026-10-24', 'opened_at' => '2026-10-24T03:00:00+00:00'];
    }
}
