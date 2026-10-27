<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Exceptions\AnalyticsError;
use App\Modules\Analytics\Export\ReportFiles;
use App\Modules\Analytics\Export\Reports;
use App\Modules\Analytics\Http\Requests\ExportRequest;
use App\Modules\Audit\Contracts\AuditedAction;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /analytics/exports/{report} (phase-4 plan section 4.6, step 4.4d; analytics.view: the admin and the manager): a report of the
 * dashboards (sales, channels, items, stations, staff, shifts) over a range of local days as a file, read from the summary tables only
 * (Export\Reports; DashboardsReadSummariesOnlyTest covers the route) and written by Export\ReportFiles: xlsx (every table and an About
 * sheet; the figures are number cells holding their decimal strings exactly), csv (the first table, a byte-order mark, the formula guard)
 * or pdf (one to three pages: the totals, the first table and the freshness stamp). At most analytics.export_max_rows rows, counted from
 * the summaries before anything is written: more is 422 analytics.export.too_large {max_rows, rows}. One audit row analytics.export
 * {report, format, locale, from, to, rows}, after the count and before the file. A download (Content-Disposition attachment) named
 * analytics-{report}-{from}-{to}.{format}, never cached. An unknown report is 404 (the route only knows the six).
 */
final class ExportsController
{
    public function show(ExportRequest $request, string $report, CurrentBranch $branch, Reports $reports, ReportFiles $files, AuditedAction $audit): BaseResponse
    {
        $range = $request->range($branch->timezone());
        $format = $request->fileFormat();
        $locale = $request->exportLocale();
        $built = $reports->build($report, $range, $locale);
        $rows = $built->rows($format);
        $max = max(1, (int) config('analytics.export_max_rows', 20000));
        if ($rows > $max) {
            throw AnalyticsError::exportTooLarge($max, $rows);
        }

        $audit->recordEvent('analytics.export', 'none', null, [
            'report' => $report, 'format' => $format, 'locale' => $locale, 'from' => $range->from, 'to' => $range->to, 'rows' => $rows,
        ]);

        $name = "analytics-{$report}-{$range->from}-{$range->to}.{$format}";
        $headers = [
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($format === 'pdf') {
            $pdf = $files->pdf($built);

            return new Response($pdf, 200, $headers + ['Content-Type' => 'application/pdf', 'Content-Length' => (string) strlen($pdf)]);
        }

        if ($format === 'csv') {
            return new StreamedResponse(static function () use ($files, $built): void {
                $out = fopen('php://output', 'wb');
                if ($out !== false) {
                    $files->csv($built, $out);
                    fclose($out);
                }
            }, 200, $headers + ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return new StreamedResponse(static function () use ($files, $built): void {
            $path = tempnam(sys_get_temp_dir(), 'analytics-export-');
            if ($path === false) {
                return;
            }
            try {
                $files->xlsx($built, $path);
                readfile($path);
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }, 200, $headers + ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
