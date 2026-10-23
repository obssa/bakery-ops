<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Contracts;

use Carbon\CarbonImmutable;

/** A locked link of a place's chain as it stood at the replayed moment. */
final readonly class ReplayLink
{
    public function __construct(public int $countId, public int $chainSeq, public string $payloadHash, public CarbonImmutable $lockedAt) {}
}
