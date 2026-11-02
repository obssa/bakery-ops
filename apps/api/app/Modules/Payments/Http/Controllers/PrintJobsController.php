<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Http\Requests\ListPrintJobsRequest;
use App\Modules\Payments\Http\Requests\PrintOrderRequest;
use App\Modules\Payments\Http\Requests\ReceiptEscposRequest;
use App\Modules\Payments\Http\Resources\PrintJobResource;
use App\Modules\Payments\Models\PrintJob;
use App\Modules\Payments\Support\Printing\PrintJobs;
use App\Modules\Payments\Support\Printing\ReceiptPrint;
use App\Shared\Actor\ActorContext;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The till's printing (step 5.3a, phase-5 plan 4.3; ADR 0019). {order} is a number, read through Orders' contract (another branch's or
 * none is 404). Station routes (tests/Support/station_routes.php).
 *
 *   POST /orders/{order}/print            print.create (admin, manager, cashier), Idempotency-Key. {copies?, client_uuid}: 202 the job,
 *                                         queued for the branch's bridge; a client_uuid already used answers its job. 409
 *                                         payments.print.not_paid (only a paid or closed order has a receipt), 409 payments.print.no_bridge
 *                                         (no bridge seen in print.bridge_seen_seconds: the till shows the receipt link at once), 409
 *                                         payments.print.disabled before both when the admin turned printing off (the go-live settings).
 *   GET  /print-jobs?order_id=            print.view, conditional (ETag over the jobs' ids, states and attempts; 304 while nothing changed):
 *                                         {data: [job]}, newest first; another branch's order has none.
 *   GET  /orders/{order}/receipt.escpos   print.view. ?width=576|384: application/octet-stream, the receipt's ESC/POS bytes for a till PC
 *                                         that prints on its own printer (10.2 D11), never cached. 409 payments.print.not_paid.
 */
final class PrintJobsController
{
    public function store(PrintOrderRequest $request, PrintJobs $jobs, int $order): JsonResponse
    {
        [$job] = $jobs->request($order, $request->copies(), $request->clientUuid());

        return response()->json((new PrintJobResource($job))->resolve($request), 202);
    }

    public function index(ListPrintJobsRequest $request, CurrentBranch $branch): SymfonyResponse
    {
        $orderId = $request->orderId();
        $state = PrintJob::query()->where('order_id', $orderId)->orderByDesc('id')->get(['id', 'status', 'attempts', 'updated_at']);
        $etag = ConditionalPoll::etag('print-jobs', [$branch->id(), $orderId, (string) json_encode($state->map(
            fn (PrintJob $job): array => [$job->id, $job->status->value, $job->attempts, $job->updated_at?->getTimestamp()],
        )->all())]);

        return ConditionalPoll::respond($request, $etag, fn (): array => [
            'data' => PrintJobResource::collection(PrintJob::query()->where('order_id', $orderId)->orderByDesc('id')->get())->resolve($request),
        ]);
    }

    public function escpos(ReceiptEscposRequest $request, ReceiptPrint $print, ActorContext $actor, int $order): Response
    {
        $bytes = $print->escpos($order, $request->width(), 1, $actor->effectiveUserId());
        $name = 'receipt-'.$order.'.escpos';

        return new Response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, $name),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
