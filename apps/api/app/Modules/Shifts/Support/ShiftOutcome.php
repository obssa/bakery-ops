<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Shifts\Models\ShiftInstance;

/** What a Shifts write answers: the shift, and whether this call made it (false: a repeat or a join of what already ran). */
final readonly class ShiftOutcome
{
    public function __construct(public ShiftInstance $shift, public bool $created) {}
}
