<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use App\Shared\Reports\SignedLink;
use App\Shared\Reports\WeeklySummaryLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Analytics' binding of Shared\Reports\WeeklySummaryLinks (step 4.4d): a temporary signed URL of GET /weekly-summaries/{week} (the public
 * route of the weekly summary's PDF) for analytics.weekly_link_days (7) days. The signature covers the week, the branch, the locale and the
 * expiry, so a link opens only the week, branch and language it was made for, and not after it expires (403
 * analytics.weekly.link_invalid). Nothing is stored and nothing is logged.
 */
final class SignedWeeklySummaryLinks implements WeeklySummaryLinks
{
    public function link(int $branchId, string $weekStart, string $locale): SignedLink
    {
        $expires = CarbonImmutable::now()->addDays(max(1, (int) config('analytics.weekly_link_days', 7)));
        $url = URL::temporarySignedRoute('analytics.weekly-summary.pdf', $expires, [
            'week' => $weekStart,
            'branch' => $branchId,
            'locale' => $locale === 'am' ? 'am' : 'en',
        ]);

        return new SignedLink($url, $expires);
    }
}
