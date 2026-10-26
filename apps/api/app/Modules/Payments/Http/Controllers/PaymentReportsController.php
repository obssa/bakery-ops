<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Http\Requests\ExportPaymentsRequest;
use App\Modules\Payments\Http\Requests\ReconciliationRequest;
use App\Modules\Payments\Support\PaymentScope;
use App\Modules\Payments\Support\PaymentsExport;
use App\Modules\Payments\Support\Reconciliation;
use App\Shared\Actor\ActorContext;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the payment checkers read in bulk (phase-4 plan section 4.3, step 4.3e; payments.verify: admin, manager).
 *
 *   GET /payments/reconciliation  per local day and channel, from payments and payment_refunds (Support\Reconciliation); polled: the ETag is
 *                                 the payments' and the refunds' fingerprints with the range and the channel, 304 when it is current.
 *                                 422 payments.reconciliation.range_too_large {max_days} beyond 62 days.
 *   GET /payments/export          the rows GET /payments finds, as csv or xlsx (Support\PaymentsExport); 422 payments.export.too_large
 *                                 {max_rows, rows} beyond payments.export_max_rows, before anything is written. One audit row
 *                                 payment.export (format, locale, filters with any reference blanked, row count). Never cached.
 */
final class PaymentReportsController
{
    public function reconciliation(ReconciliationRequest $request, Reconciliation $reconciliation, CurrentBranch $branch): Response
    {
        $range = $request->range($branch);
        $channelId = $request->channelId();
        $etag = ConditionalPoll::etag('payments-reconciliation', $reconciliation->fingerprint() + [
            'range' => [$range->from, $range->to, $range->timezone()],
            'channel_id' => $channelId,
        ]);

        return ConditionalPoll::respond($request, $etag, fn (): array => $reconciliation->report($range, $channelId));
    }

    public function export(ExportPaymentsRequest $request, PaymentsExport $export, AuditedAction $audit, ActorContext $actor, CurrentBranch $branch): Response
    {
        $filters = $request->filters();
        $format = $request->fileFormat();
        $locale = $request->exportLocale();
        $scope = PaymentScope::of($this->viewer($request), $actor);
        $max = max(1, (int) config('payments.export_max_rows', 20000));
        $rows = $export->count($filters, $scope);
        if ($rows > $max) {
            throw PaymentError::exportTooLarge($max, $rows);
        }

        $audit->recordEvent('payment.export', 'none', null, [
            'format' => $format,
            'locale' => $locale,
            'filters' => isset($filters['reference']) ? ['reference' => '[redacted]'] + $filters : $filters,
            'rows' => $rows,
        ]);

        $name = 'payments-'.($filters['from'] ?? 'all').'-'.($filters['to'] ?? CarbonImmutable::now($branch->timezone())->toDateString()).'.'.$format;
        $headers = [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new StreamedResponse(function () use ($export, $format, $filters, $scope, $locale): void {
            if ($format === 'csv') {
                $out = fopen('php://output', 'wb');
                if ($out !== false) {
                    $export->csv($out, $filters, $scope, $locale);
                    fclose($out);
                }

                return;
            }
            $path = tempnam(sys_get_temp_dir(), 'payments-export-');
            if ($path === false) {
                return;
            }
            try {
                $export->xlsx($path, $filters, $scope, $locale);
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, 200, $headers + ['Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ModelNotFoundException;
        }

        return $user;
    }
}
