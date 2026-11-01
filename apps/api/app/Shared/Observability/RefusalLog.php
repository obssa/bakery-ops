<?php

declare(strict_types=1);

namespace App\Shared\Observability;

use App\Shared\DomainRefusal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 5.5a (ADR 0021; the Phase 4 report's known gap): a domain refusal is not an error. bootstrap/app.php hands every exception the handler
 * is about to report to insteadOfReport(): for a refusal (a DomainRefusal answered with a 4xx) it writes one info line with the message_key
 * and says "do not report", so there is no ERROR line with a stack trace and nothing reaches Sentry. Anything else, including the 5xx a
 * refusal class can carry (FilesError's processing_failed and unavailable), is reported as before.
 *
 * Laravel asks once per report() (and once per Exceptions::shouldReport(), which only tests call), so a refusal leaves one line.
 */
final class RefusalLog
{
    /** True when $e is a refusal: logged here at info and not reported. False for everything else, which Laravel reports. */
    public static function insteadOfReport(Throwable $e): bool
    {
        if (! $e instanceof DomainRefusal || $e->httpStatus() >= 500) {
            return false;
        }

        Log::info('refused: '.$e->messageKey(), [
            'message_key' => $e->messageKey(),
            'status' => $e->httpStatus(),
            'class' => $e::class,
        ]);

        return true;
    }
}
