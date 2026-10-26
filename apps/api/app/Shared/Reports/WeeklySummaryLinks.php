<?php

declare(strict_types=1);

namespace App\Shared\Reports;

/**
 * A signed link to the PDF of a branch's weekly summary (phase-4 plan section 5, step 4.4d), asked for without importing Analytics: the
 * interface lives in Shared, where Notifications (which writes the message that carries the link) may import it, and Analytics binds it
 * (the technique of OpenShifts and StationPresenceResolver). Until then NoWeeklySummaryLinks answers that there is none. The link is a
 * bearer credential for its life (seven days): it is made when the message is written, so it never sits in an event, a queued job or a
 * failed job, and is never logged (the log scrub removes signature and expires).
 */
interface WeeklySummaryLinks
{
    /** The link to the week that starts on Monday $weekStart, printed in $locale (en or am); null when none can be made. */
    public function link(int $branchId, string $weekStart, string $locale): ?SignedLink;
}
