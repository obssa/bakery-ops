<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use Carbon\CarbonImmutable;

/** A person on duty at the replayed moment: joined at or before it and not yet gone (so `leftAt` is always null here). */
final readonly class ReplayDuty
{
    public function __construct(public int $userId, public CarbonImmutable $joinedAt, public ?CarbonImmutable $leftAt) {}
}
