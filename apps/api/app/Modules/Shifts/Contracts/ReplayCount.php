<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use Carbon\CarbonImmutable;

/**
 * A count in play at the replayed moment, with the status it had then (`draft`, `awaiting_cosign`, `pending_approval`, `approved`, `rejected`,
 * `locked`) worked out from its timestamps; a timestamp after the moment is null.
 */
final readonly class ReplayCount
{
    public function __construct(
        public int $countId,
        public int $shiftId,
        public int $locationId,
        public string $type,
        public string $statusAt,
        public ?CarbonImmutable $submittedAt,
        public ?CarbonImmutable $decidedAt,
        public ?CarbonImmutable $lockedAt,
    ) {}
}
