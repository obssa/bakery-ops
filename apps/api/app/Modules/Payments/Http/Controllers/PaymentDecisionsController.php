<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Actions\DisputePayment;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Http\Requests\DisputePaymentRequest;
use App\Modules\Payments\Http\Requests\VerifyPaymentRequest;
use App\Modules\Payments\Http\Requests\VoidPaymentRequest;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentAccess;
use App\Modules\Payments\Support\PaymentViews;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * What happens to a payment after it was taken (phase-4 plan section 4.4, step 4.3c). {payment} is a number looked up through the BranchScoped
 * model, so another branch's payment is 404. Every answer is the payment as GET /payments/{payment} answers it (PaymentViews::detail).
 *
 *   POST /payments/{payment}/verify    payments.verify (admin, manager). 200; recorded or disputed -> verified, a repeat on a verified payment
 *                                      answers 200 and writes nothing; 409 state_machine.illegal_transition (voided).
 *   POST /payments/{payment}/dispute   payments.verify. {reason} 3 to 255 characters (422 validation.failed). 200; recorded -> disputed;
 *                                      409 state_machine.illegal_transition (verified, disputed, voided).
 *   POST /payments/{payment}/void      orders.void_request (admin, manager, cashier) asks, the PIN of a payments.void holder approves, as
 *                                      POST /orders/{order}/void: the asker is the person acting by short code, else the account (403
 *                                      auth.forbidden without orders.void_request); the payment's state is checked first, so a refused void
 *                                      spends no PIN attempt (409 state_machine.illegal_transition, payments.void.refunded,
 *                                      payments.void.order_closed); then the PIN (403 identity.pin.rejected, 423 identity.short_code.locked),
 *                                      outside the transaction so a wrong one counts toward the lockout; then the void, one transaction. 200.
 * The PIN is never stored, logged or audited.
 */
final class PaymentDecisionsController
{
    public function __construct(
        private readonly PaymentAccess $access,
        private readonly PaymentViews $views,
    ) {}

    public function verify(VerifyPaymentRequest $request, VerifyPayment $verify, int $payment): JsonResponse
    {
        $row = $this->find($payment);
        $by = $this->access->person('payments.verify');

        return response()->json($this->views->detail($verify->handle($row, $by, $request->note())));
    }

    public function dispute(DisputePaymentRequest $request, DisputePayment $dispute, int $payment): JsonResponse
    {
        $row = $this->find($payment);
        $by = $this->access->person('payments.verify');

        return response()->json($this->views->detail($dispute->handle($row, $by, $request->reason())));
    }

    public function void(VoidPaymentRequest $request, VoidPayment $void, int $payment): JsonResponse
    {
        $row = $this->find($payment);
        $requester = $this->access->person('orders.void_request');
        $void->assertVoidable($row);
        $approver = $this->access->voidApprover($request->pin());

        return response()->json($this->views->detail($void->handle($row, $requester, $approver, $request->reason())));
    }

    private function find(int $payment): Payment
    {
        $row = Payment::query()->find($payment);
        if (! $row instanceof Payment) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$payment]);
        }

        return $row;
    }
}
