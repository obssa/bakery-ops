<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\OrderRefundView;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Support\RefundOutcome;
use App\Modules\Payments\Support\ShiftRule;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\RefundPaid;
use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Shifts\OpenShifts;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Ramsey\Uuid\Uuid;

/**
 * POST /orders/{order}/refund (phase-4 plan sections 4.4 and 10.4, step 4.3d; Ramin's rule of 2026-10-28, 10.2 R-C): the money side of a
 * refund, on top of Orders::refund (step 4.1b: the stock and order side). The cashier asks (orders.void_request), the PIN of a payments.void
 * holder approves (R-N); the controller makes the checks of assertRefundable() first, so a refused refund spends no PIN attempt, then asks
 * Identity for the PIN outside any transaction (a wrong one counts toward the lockout), then handle():
 *   - one transaction: the order row is locked first (Orders::forPayment, the lock a payment and a void take), then Orders::refund (its lines,
 *     the ledger: a `return` per restocked line, the shift rule of the place), then the order's payments FOR UPDATE in id order;
 *   - the refund's amount goes back over the order's payments last in, first out by (received_at, id) descending, each taking up to what it
 *     still holds (amount - refunded_amount), or as the `allocation` names it (each share within what its payment holds, the shares adding up
 *     to the refund; 422 payments.refund.allocation_invalid otherwise);
 *   - a cash share leaves the drawer, so it needs the till's shift the asker carries, open (payments.require_shift_for_cash; every channel
 *     when payments.require_shift says so: 409 payments.shift.required); a digital share is a transfer made outside the app, recorded with
 *     its reference (422 payments.refund.reference_required) and, optionally, the photo of it: a refund_proof upload, unattached and young
 *     enough (422 payments.refund.proof_invalid), which the first digital row claims;
 *   - one payment_refunds row per share (the payment's channel, the actor stamp: refunded_by = the asker, approved_by = the approver, the
 *     shift the money left in), each payment's refunded_amount raised by its share; RefundPaid follows the commit;
 *   - ONE audit row (order.refund, the actor is the asker), written last in the same transaction, its context ids and amounts only.
 * A refund already made under the client_uuid answers it as it was (created false), writing nothing. Any refusal rolls everything back,
 * the stock and the order included. The PIN, the transfer's reference and the photo's key are never logged or audited.
 */
final class RefundPayments
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Orders $orders,
        private readonly ActorContext $actor,
        private readonly OpenShifts $shifts,
        private readonly Files $files,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * The checks that need no PIN, writing nothing: Orders' (the order, its lines, the window, R-D, the products' settings), then the
     * allocation's shares and the proof photo as they are now. A refund already made under $clientUuid passes (handle() answers it).
     *
     * @param  list<int>  $lineIds
     * @param  list<array{payment_id: int, amount: string}>|null  $allocation
     *
     * @throws PaymentError payments.refund.allocation_invalid, payments.refund.proof_invalid
     * @throws ModelNotFoundException
     */
    public function assertRefundable(int $orderId, array $lineIds, string $clientUuid, ?array $allocation, ?int $proofFileId): void
    {
        $this->orders->assertRefundable($orderId, $lineIds, $clientUuid);
        $payments = $this->payments($orderId, false);
        if ($this->replayed($payments, $clientUuid)) {
            return;
        }
        if ($allocation !== null) {
            $this->checkShares($payments, $allocation);
        }
        if ($proofFileId !== null) {
            $this->proof($proofFileId, false);
        }
    }

    /**
     * @param  list<int>  $lineIds  order-line ids; empty: every refundable line
     * @param  list<array{payment_id: int, amount: string}>|null  $allocation  null: last in, first out
     *
     * @throws PaymentError
     * @throws ModelNotFoundException
     */
    public function handle(
        int $orderId,
        User $requester,
        User $approver,
        string $reason,
        array $lineIds,
        string $clientUuid,
        ?array $allocation = null,
        ?string $reference = null,
        ?int $proofFileId = null,
    ): RefundOutcome {
        /** @var RefundOutcome $outcome */
        $outcome = $this->db->connection()->transaction(function () use ($orderId, $requester, $approver, $reason, $lineIds, $clientUuid, $allocation, $reference, $proofFileId): RefundOutcome {
            $this->orders->forPayment($orderId, true);
            $refund = $this->orders->refund($orderId, $reason, $approver, $lineIds, $clientUuid);
            $done = PaymentRefund::query()->where('order_refund_id', $refund->id)->orderBy('id')->get();
            if ($done->isNotEmpty()) {
                return new RefundOutcome($refund, array_values($done->all()), false);
            }

            $payments = $this->payments($orderId, true);
            $shares = $allocation === null
                ? $this->lastInFirstOut($payments, Money::of($refund->amount))
                : $this->checkShares($payments, $allocation, Money::of($refund->amount));
            $this->assertShift($shares);
            $digital = array_values(array_filter($shares, static fn (array $s): bool => ! $s['payment']->channel->isCash()));
            if ($digital !== [] && $reference === null) {
                throw PaymentError::refundReferenceRequired($digital[0]['payment']->channel->code);
            }
            $proof = $proofFileId === null ? null : $this->proof($proofFileId, true);

            $rows = $this->write($refund, $clientUuid, $shares, $requester, $approver, $reference, $digital === [] ? null : $proof);
            $claimer = $proof instanceof StoredFile ? collect($rows)->first(fn (PaymentRefund $r): bool => $r->proof_file_id === $proof->id) : null;
            if ($proof instanceof StoredFile && $claimer instanceof PaymentRefund) {
                // the first digital row claims the photo of the transfer; a refund with no digital share leaves it unclaimed
                $this->files->attach($proof, 'payment_refund', $claimer->id);
            }

            $summary = $this->orders->summaries([$orderId])[$orderId] ?? null;
            event(new RefundPaid(
                $rows[0]->branch_id,
                $refund->id,
                $orderId,
                $summary?->orderNo,
                $refund->amount,
                $refund->isFull,
                $refund->reason,
                $requester->id,
                $approver->id,
                $approver->name,
                array_map(static fn (PaymentRefund $r): array => [
                    'payment_id' => $r->payment_id, 'channel_code' => $r->channel->code, 'amount' => $r->amount->amount(),
                ], $rows),
            ));

            $outcome = new RefundOutcome($refund, $rows, true);

            return $this->audit->run(static fn (): RefundOutcome => $outcome, null, 'order.refund', [
                'order_id' => $orderId,
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
                'is_full' => $refund->isFull,
                'reason' => $refund->reason,
                'line_ids' => $refund->lineIds(),
                'movement_ids' => $refund->movementIds(),
                'approved_by_user_id' => $approver->id,
                'payments' => array_map(static fn (PaymentRefund $r): array => [
                    'payment_refund_id' => $r->id, 'payment_id' => $r->payment_id, 'channel_code' => $r->channel->code, 'amount' => $r->amount->amount(),
                ], $rows),
            ]);
        });

        return $outcome;
    }

    /**
     * The order's payments, in id order, locked when $lock (inside the refund's transaction, after the order's row).
     *
     * @return Collection<int, Payment>
     */
    private function payments(int $orderId, bool $lock): Collection
    {
        $query = Payment::query()->with('channel')->where('order_id', $orderId)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @param  Collection<int, Payment>  $payments */
    private function replayed(Collection $payments, string $clientUuid): bool
    {
        if ($payments->isEmpty()) {
            return false;
        }
        $uuids = $payments->map(fn (Payment $p): string => self::rowUuid($p->branch_id, $clientUuid, $p->id))->all();

        return PaymentRefund::query()->whereIn('client_uuid', $uuids)->exists();
    }

    /**
     * Last in, first out by (received_at, id) descending: each payment that counts gives back up to what it still holds.
     *
     * @param  Collection<int, Payment>  $payments
     * @return list<array{payment: Payment, amount: Money}>
     */
    private function lastInFirstOut(Collection $payments, Money $amount): array
    {
        $ordered = $payments->filter(fn (Payment $p): bool => $p->status !== PaymentStatus::Voided)
            ->sort(fn (Payment $a, Payment $b): int => [$b->received_at->getTimestamp(), $b->id] <=> [$a->received_at->getTimestamp(), $a->id]);
        $left = $amount;
        $shares = [];
        foreach ($ordered as $payment) {
            if (! $left->isPositive()) {
                break;
            }
            $open = self::open($payment);
            if (! $open->isPositive()) {
                continue;
            }
            $take = $open->isGreaterThan($left) ? $left : $open;
            $shares[] = ['payment' => $payment, 'amount' => $take];
            $left = $left->minus($take);
        }
        if ($left->isPositive()) {
            // Orders refused a refund beyond paid_total (orders.refund.exceeds_paid), so the payments that count always hold it
            throw new LogicException("The payments of the order hold {$amount->minus($left)->amount()} of a refund of {$amount->amount()}.");
        }

        return $shares;
    }

    /**
     * The shares an allocation names, each a payment of the order that counts, named once, more than zero and within what it still holds;
     * with $amount, they must add up to it.
     *
     * @param  Collection<int, Payment>  $payments
     * @param  list<array{payment_id: int, amount: string}>  $allocation
     * @return list<array{payment: Payment, amount: Money}>
     *
     * @throws PaymentError payments.refund.allocation_invalid
     */
    private function checkShares(Collection $payments, array $allocation, ?Money $amount = null): array
    {
        $byId = $payments->keyBy('id');
        $seen = [];
        $sum = Money::zero();
        $shares = [];
        foreach ($allocation as $share) {
            $id = $share['payment_id'];
            if (isset($seen[$id])) {
                throw PaymentError::refundAllocationInvalid('repeated', $id);
            }
            $seen[$id] = true;
            $payment = $byId->get($id);
            if (! $payment instanceof Payment || $payment->status === PaymentStatus::Voided) {
                throw PaymentError::refundAllocationInvalid('payment', $id);
            }
            $money = Money::of($share['amount']);
            if (! $money->isPositive()) {
                throw PaymentError::refundAllocationInvalid('amount', $id);
            }
            if ($money->isGreaterThan(self::open($payment))) {
                throw PaymentError::refundAllocationInvalid('exceeds', $id);
            }
            $sum = $sum->plus($money);
            $shares[] = ['payment' => $payment, 'amount' => $money];
        }
        if ($amount !== null && ! $sum->equals($amount)) {
            throw PaymentError::refundAllocationInvalid('sum');
        }

        return $shares;
    }

    /**
     * The shift rule (ShiftRule): when a share needs the till's shift (cash, or every channel when payments.require_shift says so), the asker
     * must carry an OPEN one; its row is read under a shared lock, so a count that begins now waits for the refund or sees it.
     *
     * @param  list<array{payment: Payment, amount: Money}>  $shares
     *
     * @throws PaymentError payments.shift.required (409)
     */
    private function assertShift(array $shares): void
    {
        $needed = false;
        foreach ($shares as $share) {
            $needed = $needed || ShiftRule::needsShift($share['payment']->channel->isCash());
        }
        if (! $needed) {
            return;
        }
        $shift = $this->actor->shiftInstanceId();
        if ($shift === null || ! $this->shifts->isOpen($shift, true)) {
            throw PaymentError::shiftRequired();
        }
    }

    /**
     * The photo of the outgoing transfer: a refund_proof upload of the branch, not claimed, younger than payments.photo_max_age_hours
     * (the age a receipt photo may have), its row locked when $lock.
     *
     * @throws PaymentError payments.refund.proof_invalid
     */
    private function proof(int $fileId, bool $lock): StoredFile
    {
        $query = StoredFile::query()->whereKey($fileId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $file = $query->first();
        if (! $file instanceof StoredFile) {
            throw PaymentError::refundProofInvalid('not_found');
        }
        if ($file->purpose !== 'refund_proof') {
            throw PaymentError::refundProofInvalid('wrong_purpose');
        }
        if ($file->attached_type !== null || $file->attached_at !== null) {
            throw PaymentError::refundProofInvalid('attached');
        }
        $oldest = CarbonImmutable::now()->subHours((int) config('payments.photo_max_age_hours', 12));
        if ($file->created_at === null || $file->created_at->lessThan($oldest)) {
            throw PaymentError::refundProofInvalid('expired');
        }

        return $file;
    }

    /**
     * @param  list<array{payment: Payment, amount: Money}>  $shares
     * @return list<PaymentRefund>
     */
    private function write(OrderRefundView $refund, string $clientUuid, array $shares, User $requester, User $approver, ?string $reference, ?StoredFile $proof): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $rows = [];
        foreach ($shares as $share) {
            $payment = $share['payment'];
            $digital = ! $payment->channel->isCash();
            $row = new PaymentRefund;
            $row->forceFill([
                'branch_id' => $payment->branch_id,
                'client_uuid' => self::rowUuid($payment->branch_id, $clientUuid, $payment->id),
                'order_id' => $payment->order_id,
                'order_refund_id' => $refund->id,
                'payment_id' => $payment->id,
                'channel_id' => $payment->channel_id,
                'amount' => $share['amount'],
                'reference_no' => $digital ? $reference : null,
                'proof_file_id' => $digital ? $proof?->id : null,
                'refunded_by_user_id' => $requester->id,
                'approved_by_user_id' => $approver->id,
                'acting_user_id' => $this->actor->actingUserId(),
                'shift_instance_id' => $this->actor->shiftInstanceId(),
                'station_id' => $this->actor->stationId(),
                'device_id' => $this->actor->deviceId(),
                'refunded_at' => $now,
                'created_at' => $now,
            ])->save();
            $row->setRelation('channel', $payment->channel);
            $payment->forceFill(['refunded_amount' => self::refunded($payment)->plus($share['amount'])])->save();
            $rows[] = $row;
        }

        return $rows;
    }

    /** A money row's own id: the refund's client_uuid and the payment it goes back through (unique per branch, ADR 0003). */
    public static function rowUuid(int $branchId, string $refundClientUuid, int $paymentId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'payment-refund:'.$branchId.':'.strtolower($refundClientUuid).':'.$paymentId)->toString();
    }

    private static function open(Payment $payment): Money
    {
        return $payment->amount->minus(self::refunded($payment));
    }

    private static function refunded(Payment $payment): Money
    {
        return $payment->refunded_amount instanceof Money ? $payment->refunded_amount : Money::zero();
    }
}
