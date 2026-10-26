<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Payments\Http\Requests\ReceiptLinkRequest;
use App\Modules\Payments\Http\Requests\ReceiptPdfRequest;
use App\Modules\Payments\Support\PaymentAccess;
use App\Modules\Payments\Support\Receipts\Receipt;
use App\Modules\Payments\Support\Receipts\ReceiptBuilder;
use App\Modules\Payments\Support\Receipts\ReceiptRenderer;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Receipts (phase-4 plan section 4.5, step 4.3e). {order} is a number; the order is read through Orders::forReceipt, so another branch's
 * order, or none, is 404. The PDF is ReceiptRenderer's (at most two pages, no person named, a reference by its last 4 characters only),
 * answered inline and never cached (Cache-Control: private, no-store).
 *
 *   GET /orders/{order}/receipt.pdf    payments.view (admin, manager, cashier). ?locale=en|am|both, ?proforma=1. 409 payments.receipt.unpaid
 *                                      {status} for an order not paid in full (a pro-forma when asked) and for a cancelled or voided one.
 *   POST /orders/{order}/receipt-link  payments.take (the till's roles; the person acting by short code must hold it too), Idempotency-Key.
 *                                      {ttl_hours?, locale?}: 200 {url, expires_at}, a signed link to the public receipt; one audit row
 *                                      receipt.link (order, lifetime, expiry, locale; never the URL, a credential while it lives). 409 as above.
 *   GET /receipts/{order}              public (tests/Support/public_routes.php): no bearer token, the `signed` middleware is the whole
 *                                      protection, and the signature covers the order, its branch, the locale and the expiry (a changed one
 *                                      is 403 payments.receipt.link_invalid). The branch is the one the link names: it is set as the current
 *                                      branch, so every read below is scoped to it as for a signed-in user.
 */
final class ReceiptsController
{
    public function __construct(
        private readonly ReceiptBuilder $builder,
        private readonly ReceiptRenderer $renderer,
    ) {}

    public function pdf(ReceiptPdfRequest $request, int $order): Response
    {
        return $this->respond($this->builder->build($order, $request->locale(), $request->proforma()));
    }

    public function link(ReceiptLinkRequest $request, PaymentAccess $access, AuditedAction $audit, CurrentBranch $branch, int $order): JsonResponse
    {
        $access->receiver();
        $this->builder->build($order);
        $ttl = $request->ttlHours();
        $locale = $request->locale();
        $expires = CarbonImmutable::now()->addHours($ttl);
        $url = URL::temporarySignedRoute('payments.receipts.show', $expires, ['order' => $order, 'branch' => $branch->id()] + ($locale === null ? [] : ['locale' => $locale]));

        $audit->recordEvent('receipt.link', 'order', $order, [
            'order_id' => $order,
            'ttl_hours' => $ttl,
            'expires_at' => $expires->toIso8601String(),
            'locale' => $locale,
        ]);

        return response()->json(['url' => $url, 'expires_at' => $expires->toIso8601String()]);
    }

    public function show(Request $request, CurrentBranch $current, int $order): Response
    {
        $branch = Branch::query()->find((int) $request->query('branch'));
        if (! $branch instanceof Branch) {
            throw new ModelNotFoundException;
        }
        $current->set($branch);
        $locale = $request->query('locale');

        return $this->respond($this->builder->build($order, is_string($locale) && in_array($locale, ReceiptBuilder::LOCALES, true) ? $locale : null))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function respond(Receipt $receipt): Response
    {
        $pdf = $this->renderer->render($receipt);
        $name = $receipt->fileName();

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $name, $name),
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
