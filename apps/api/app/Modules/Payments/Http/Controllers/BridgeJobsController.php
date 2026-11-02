<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Identity\Contracts\Bridges;
use App\Modules\Payments\Http\Requests\BridgeJobRequest;
use App\Modules\Payments\Support\Printing\PrintJobs;
use App\Modules\Payments\Support\Printing\ReceiptPrint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The print bridge's routes (step 5.3a, phase-5 plan 4.3; ADR 0019). No user: the `bridge` middleware signs the bridge in with its token
 * (Authorization: Bridge <token>; 401 identity.bridge.unknown | revoked) and makes its branch the current one, so {job} of another branch
 * is 404. The jobs' lives are PrintJobs'; the bytes are ReceiptPrint's.
 *
 *   GET  /bridge/jobs/next           204 when nothing waits (one indexed read, no write but last_seen_at once a minute); 200 {job_id}
 *   POST /bridge/jobs/{job}/claim    {width?, client_uuid}: 200 {job_id, order_number, copies, escpos_base64, expires_at}; 409
 *                                    payments.print.already_claimed; 410 payments.print.expired
 *   POST /bridge/jobs/{job}/printed  {client_uuid}: 204; 409 payments.print.not_claimed_by_you
 *   POST /bridge/jobs/{job}/failed   {failure_key, client_uuid}: 204; 409 payments.print.not_claimed_by_you
 * A repeated claim, printed or failed of the bridge that holds the job answers the same and changes nothing (the bridge retries when an
 * answer was lost); the Idempotency-Key header is not needed.
 */
final class BridgeJobsController
{
    public function __construct(private readonly PrintJobs $jobs) {}

    public function next(): JsonResponse|Response
    {
        $id = $this->jobs->next();

        return $id === null ? response()->noContent() : response()->json(['job_id' => $id]);
    }

    public function claim(BridgeJobRequest $request, Bridges $bridges, ReceiptPrint $print, int $job): JsonResponse
    {
        $claimed = $this->jobs->claim($bridges->current($request), $job, $request->clientUuid());
        $receipt = $print->render($claimed->order_id, $request->width(), $claimed->copies, $claimed->cashierId());

        return response()->json([
            'job_id' => $claimed->id,
            'order_number' => $receipt['order_no'],
            'copies' => $claimed->copies,
            'escpos_base64' => base64_encode($receipt['bytes']),
            'expires_at' => $claimed->expiresAt()->toIso8601String(),
        ]);
    }

    public function printed(BridgeJobRequest $request, Bridges $bridges, int $job): Response
    {
        $this->jobs->printed($bridges->current($request), $job, $request->clientUuid());

        return response()->noContent();
    }

    public function failed(BridgeJobRequest $request, Bridges $bridges, int $job): Response
    {
        $this->jobs->failed($bridges->current($request), $job, $request->failureKey(), $request->clientUuid());

        return response()->noContent();
    }
}
