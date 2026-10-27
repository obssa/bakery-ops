<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Audit\Exceptions\AuditError;
use App\Modules\Audit\Http\Requests\ExportAuditLogRequest;
use App\Modules\Audit\Http\Requests\ListAuditLogRequest;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Read\AuditEntries;
use App\Modules\Audit\Read\AuditExport;
use App\Modules\Audit\Read\AuditLogQuery;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log, read (phase-4 plan section 4.7, step 4.5a; the screen is step 4.5f). Every route: auth:sanctum, the actor middleware and a
 * permission (routes/modules/audit.php). Reads only, never cached; the rows are redacted on every read (Read\AuditEntries), never rewritten.
 *
 *   GET /audit-log          audit.view (admin, manager)  200 {data: [Entry], next_cursor}: newest first, the filters of ListAuditLogRequest
 *   GET /audit-log/{id}     audit.view                   200 Entry with before, after and context; 404 http.not_found (another branch's too)
 *   GET /audit-log/export   audit.export (admin)         the file (Read\AuditExport), csv or xlsx; 422 audit.export.too_large {max_rows, rows}
 *                                                         beyond audit.export_max_rows, before anything is written; one audit row
 *                                                         audit.export (format, locale, filters, rows), which the file itself never holds
 */
final class AuditLogController
{
    public function index(ListAuditLogRequest $request, AuditLogQuery $log, AuditEntries $entries): JsonResponse
    {
        [$rows, $next] = $log->page($request->filters());

        return response()->json(['data' => $entries->list($rows), 'next_cursor' => $next]);
    }

    public function show(AuditEntries $entries, int $id): JsonResponse
    {
        return response()->json($entries->detail(AuditLog::query()->findOrFail($id)));
    }

    public function export(ExportAuditLogRequest $request, AuditExport $export, AuditedAction $audit, CurrentBranch $branch): StreamedResponse
    {
        $filters = $request->filters();
        $format = $request->fileFormat();
        $locale = $request->exportLocale();
        $upTo = $export->lastId($filters);
        $rows = $export->count($filters, $upTo);
        $max = max(1, (int) config('audit.export_max_rows', 50000));
        if ($rows > $max) {
            throw AuditError::exportTooLarge($max, $rows);
        }

        $audit->recordEvent('audit.export', 'none', null, ['format' => $format, 'locale' => $locale, 'filters' => $filters, 'rows' => $rows]);

        $name = 'audit-log-'.($filters['from'] ?? 'all').'-'.($filters['to'] ?? CarbonImmutable::now($branch->timezone())->toDateString()).'.'.$format;
        $headers = [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new StreamedResponse(function () use ($export, $format, $filters, $upTo, $locale): void {
            if ($format === 'csv') {
                $out = fopen('php://output', 'wb');
                if ($out !== false) {
                    $export->csv($out, $filters, $upTo, $locale);
                    fclose($out);
                }

                return;
            }
            $path = tempnam(sys_get_temp_dir(), 'audit-export-');
            if ($path === false) {
                return;
            }
            try {
                $export->xlsx($path, $filters, $upTo, $locale);
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, 200, $headers);
    }
}
