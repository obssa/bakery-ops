<?php

declare(strict_types=1);

namespace App\Shared\Reports;

use Carbon\CarbonImmutable;

/** A signed URL and the moment it stops opening. */
final readonly class SignedLink
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
