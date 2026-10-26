<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Shifts\OpenShifts;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;

/**
 * The part of taking a payment that runs inside its transaction, after Orders::forPayment locked the order (RecordPayment and
 * RecordPaymentBatch share it, so one payment and a split bill are written the same way). Locks are taken in one order everywhere: the
 * order row, then the till's shift row (shared), then the receipt photos' rows in ascending id order, so two tills never deadlock.
 */
final class PaymentWriter
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
        private readonly OpenShifts $shifts,
        private readonly Files $files,
    ) {}

    /**
     * The shift rule (ShiftRule): when any of the payments needs the till's shift, the actor must carry an OPEN one; the shared lock on its
     * row keeps a close (which takes it FOR UPDATE) from freezing the drawer between this check and the payment rows, so a payment is
     * either inside the declared shift or refused.
     *
     * @param  list<CheckedPayment>  $payments
     *
     * @throws PaymentError payments.shift.required (409)
     */
    public function assertShift(array $payments): void
    {
        $needed = false;
        foreach ($payments as $payment) {
            $needed = $needed || ShiftRule::needsShift($payment->channel->isCash());
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
     * Cash carries what was handed over and the change; tendered must cover the amount (a cash payment may be a part of the bill, the
     * customer may hand over more than the part). Null for any other channel, as in Phase 1.
     *
     * @throws PaymentError payments.tendered.too_low (422)
     */
    public function tender(CheckedPayment $payment, Money $amount, string $currency): ?CashTender
    {
        if (! $payment->channel->isCash()) {
            return null;
        }
        $tendered = $payment->input->tenderedAmount === null ? null : Money::of($payment->input->tenderedAmount, $currency);
        if ($tendered instanceof Money && ! CashTender::covers($amount, $tendered)) {
            throw PaymentError::tenderedTooLow($amount->amount(), $tendered->amount());
        }

        return CashTender::of($amount, $tendered);
    }

    /**
     * The receipt photos' rows, locked FOR UPDATE in ascending id order; a file removed meanwhile is missing from the answer.
     *
     * @param  list<CheckedPayment>  $payments
     * @return array<int, StoredFile> by id
     */
    public function lockPhotos(array $payments): array
    {
        $ids = [];
        foreach ($payments as $payment) {
            if ($payment->file instanceof StoredFile) {
                $ids[] = $payment->file->id;
            }
        }
        if ($ids === []) {
            return [];
        }
        sort($ids);

        return StoredFile::query()->whereIn('id', array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
    }

    /**
     * The photo of $payment as it is now, under its lock: still there and still free.
     *
     * @param  array<int, StoredFile>  $locked  lockPhotos()
     *
     * @throws PaymentError payments.photo.invalid
     */
    public function lockedPhoto(CheckedPayment $payment, array $locked): ?StoredFile
    {
        if (! $payment->file instanceof StoredFile) {
            return null;
        }
        $file = $locked[$payment->file->id] ?? null;
        if (! $file instanceof StoredFile) {
            throw PaymentError::photoInvalid('not_found');
        }
        if ($file->attached_type !== null || $file->attached_at !== null) {
            throw PaymentError::photoInvalid('attached');
        }

        return $file;
    }

    /**
     * Writes the payment row with the actor stamp, attaches its photo, and dispatches PaymentRecorded inside the transaction, where Orders'
     * ApplyPayment adds it to the order (and settles, pays and closes the order once the balance reaches zero). receipt_photo_key is the
     * photo's object key, as the Phase 1 column always meant; it is never logged or answered.
     */
    public function write(
        CheckedPayment $payment,
        int $orderId,
        Money $amount,
        ?CashTender $tender,
        string $currency,
        Money $balanceAfter,
        ?string $batchUuid,
        User $receivedBy,
        ?StoredFile $photo,
    ): Payment {
        $row = new Payment;
        $row->forceFill([
            'branch_id' => $this->branch->id(),
            'client_uuid' => $payment->input->clientUuid,
            'batch_uuid' => $batchUuid,
            'order_id' => $orderId,
            'channel_id' => $payment->channel->id,
            'amount' => $amount,
            'tendered_amount' => $tender?->tendered,
            'change_amount' => $tender?->change,
            'currency' => $currency,
            'reference_no' => $payment->referenceNo,
            'reference_norm' => $payment->referenceNorm,
            'receipt_photo_key' => $photo?->getAttribute('key'),
            'receipt_file_id' => $photo?->id,
            'status' => PaymentStatus::Recorded,
            'received_by_user_id' => $receivedBy->id,
            'acting_user_id' => $this->actor->actingUserId(),
            'shift_instance_id' => $this->actor->shiftInstanceId(),
            'station_id' => $this->actor->stationId(),
            'device_id' => $this->actor->deviceId(),
            'received_at' => CarbonImmutable::now()->startOfSecond(),
        ])->save();

        if ($photo instanceof StoredFile) {
            $this->files->attach($photo, 'payment', $row->id);
        }

        event(new PaymentRecorded($this->branch->id(), $row->id, $orderId, $payment->channel->code, $amount->amount(), $balanceAfter->amount(), null, $photo instanceof StoredFile));

        return $row;
    }
}
