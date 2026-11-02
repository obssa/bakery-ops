<?php

declare(strict_types=1);

namespace App\Ops\Http;

use App\Ops\Status\SystemStatus;
use App\Shared\Live\ConditionalPoll;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /system/status (phase-5 plan 4.4, step 5.5c; system.view, the admin): the System page's one read, polled every 30 s. A conditional
 * read (ADR 0009): the ETag is taken over the body itself, which is a handful of indexed counts and a folder listing of some 30 files, so
 * an unchanged status answers 304 with no body; the heartbeat moves the tag about once a minute. No branch filter beyond the devices: the
 * rest is the host's own state (one host, one database).
 */
final class SystemStatusController
{
    /**
     * The status of the host (SystemStatus): app, scheduler, queue, backups, files, devices, flags, error_tracking.
     *
     * @throws JsonException
     */
    public function show(Request $request, SystemStatus $status): Response
    {
        $body = $status->read();
        $etag = ConditionalPoll::etag('system.status', [json_encode($body, JSON_THROW_ON_ERROR)]);

        return ConditionalPoll::respond($request, $etag, static fn (): array => $body);
    }
}
