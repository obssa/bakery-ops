<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** DeviceCensus's answer (step 5.5c): the branch's devices as three counts. */
final readonly class DeviceCount
{
    public function __construct(
        public int $enrolled,
        public int $seenRecently,
        public int $revoked,
    ) {}
}
