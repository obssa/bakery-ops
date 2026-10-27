<?php

declare(strict_types=1);

namespace App\Shared\Reports;

/** The binding until Analytics binds its own (and in any environment without it): no link can be made. */
final class NoWeeklySummaryLinks implements WeeklySummaryLinks
{
    public function link(int $branchId, string $weekStart, string $locale): ?SignedLink
    {
        return null;
    }
}
