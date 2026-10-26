<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Requests\WeeklySummaryRequest;
use App\Modules\Analytics\Weekly\WeeklySummary;
use App\Modules\Analytics\Weekly\WeeklySummaryPdf;
use App\Modules\Audit\Contracts\AuditedAction;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\WeeklySummaryReady;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The weekly summary (phase-4 plan sections 4.6 and 5, step 4.4d).
 *   POST /analytics/weekly-summary/send  analytics.manage (the admin), Idempotency-Key. {week_of?}: builds the summary the Monday job builds
 *                                        (WeeklySummary, from the summaries) of that week or of the last complete one, and announces
 *                                        WeeklySummaryReady again, every time (run "manual-<uuid>", so a new message and a new Telegram
 *                                        try each time; a replay of the same key answers the stored 202 and announces nothing). One audit
 *                                        row analytics.weekly_summary.send {week_start, week_end, run}. 202 {week_start, week_end, run,
 *                                        summary}: Notifications delivers it from the queue.
 *   GET /weekly-summaries/{week}         public (tests/Support/public_routes.php): the PDF the message's signed seven-day link opens, no
 *                                        bearer token (a phone opening it from Telegram has none). The `signed` middleware is the whole
 *                                        protection (week, branch, locale and expiry; a changed or expired link is 403
 *                                        analytics.weekly.link_invalid). The branch the link names is set as the current branch, so the
 *                                        summary is read as for a signed-in user of it; built when it is opened, so its stamp says as of
 *                                        when. Never cached, never indexed.
 */
final class WeeklySummaryController
{
    public function send(WeeklySummaryRequest $request, CurrentBranch $branch, WeeklySummary $weekly, AuditedAction $audit, ActorContext $actor): JsonResponse
    {
        $weekStart = $request->weekStart($branch->timezone());
        $weekEnd = WeeklySummary::weekEnd($weekStart);
        $summary = $weekly->build($weekStart);
        $run = 'manual-'.Str::uuid()->toString();

        $audit->recordEvent('analytics.weekly_summary.send', 'none', null, ['week_start' => $weekStart, 'week_end' => $weekEnd, 'run' => $run]);
        event(new WeeklySummaryReady($branch->id(), $weekStart, $weekEnd, $run, $summary, $actor->effectiveUserId() ?? $actor->requireUserId()));

        return response()->json(['week_start' => $weekStart, 'week_end' => $weekEnd, 'run' => $run, 'summary' => $summary], 202);
    }

    public function pdf(Request $request, CurrentBranch $current, WeeklySummary $weekly, WeeklySummaryPdf $pdfs, string $week): Response
    {
        $branch = Branch::query()->find((int) $request->query('branch'));
        if (! $branch instanceof Branch) {
            throw new ModelNotFoundException;
        }
        try {
            if (WeeklySummary::weekOf($week) !== $week) {
                throw new ModelNotFoundException;
            }
        } catch (InvalidArgumentException) {
            throw new ModelNotFoundException;
        }
        $current->set($branch);
        $locale = $request->query('locale') === 'am' ? 'am' : 'en';

        $pdf = $pdfs->render($weekly->build($week), $locale);
        $name = "weekly-summary-{$week}.pdf";

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, $name),
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
